<?php
session_start();
require_once "../../config/database.php";

// Check user role - this would come from your authentication system
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error_message'] = "Access denied. Please log in as an administrator.";
    header("Location: ../../index.php");
    exit();
}

// Function to get the active term or the latest term if no active term is found
function getActiveTerm() {
    try {
        // Check if we have a specific term year in session (from creating a new term)
        if (isset($_SESSION['new_term_year'])) {
            $year = $_SESSION['new_term_year'];
            $termQuery = "SELECT id, year FROM Static WHERE year = ?";
            $stmt = prepare($termQuery);
            $stmt->bind_param("i", $year);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                return $result->fetch_assoc();
            }
        } else {
            // Otherwise, get the active term
            $termQuery = "SELECT id, year FROM Static WHERE status = 'active'";
            $result = search($termQuery);
            if ($result && $result->num_rows > 0) {
                return $result->fetch_assoc();
            } else {
                // If no active term, get the latest term
                $termQuery = "SELECT id, year FROM Static ORDER BY year DESC LIMIT 1";
                $result = search($termQuery);
                if ($result && $result->num_rows > 0) {
                    return $result->fetch_assoc();
                }
            }
        }
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error retrieving term data: " . $e->getMessage();
    }
    return null;
}

// Function to get current active officers
function getCurrentActiveOfficers() {
    try {
        $activeTerm = getActiveTerm();
        if (!$activeTerm) {
            return null;
        }
        
        $termYear = $activeTerm['year'];
        
        $query = "SELECT t.MemberID as treasurerMemberID, a.MemberID as auditorMemberID 
                 FROM Treasurer t 
                 LEFT JOIN Auditor a ON a.Term = t.Term AND a.isActive = 1
                 WHERE t.Term = ? AND t.isActive = 1";
        
        $stmt = prepare($query);
        $stmt->bind_param("i", $termYear);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result && $result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        
        return ['treasurerMemberID' => null, 'auditorMemberID' => null];
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error retrieving current officers: " . $e->getMessage();
        return null;
    }
}

// Function to generate a new ID for Auditor in the format AUDI01, AUDI02, etc.
function generateAuditorID($conn) {
    try {
        // Get the last used auditor ID
        $stmt = $conn->prepare("SELECT AuditorID FROM Auditor ORDER BY AuditorID DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $lastID = $result->fetch_assoc()['AuditorID'];
            // Extract numeric part, increment it, and format with leading zeros
            $numericPart = intval(substr($lastID, 4)); // Extract after "AUDI"
            $nextNumeric = $numericPart + 1;
            return 'AUDI' . str_pad($nextNumeric, 2, '0', STR_PAD_LEFT);
        } else {
            // First auditor
            return 'AUDI01';
        }
    } catch(Exception $e) {
        throw new Exception("Error generating Auditor ID: " . $e->getMessage());
    }
}

// Function to generate a new ID for Treasurer in the format TRES01, TRES02, etc.
function generateTreasurerID($conn) {
    try {
        // Get the last used treasurer ID
        $stmt = $conn->prepare("SELECT TreasurerID FROM Treasurer ORDER BY TreasurerID DESC LIMIT 1");
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $lastID = $result->fetch_assoc()['TreasurerID'];
            // Extract numeric part, increment it, and format with leading zeros
            $numericPart = intval(substr($lastID, 4)); // Extract after "TRES"
            $nextNumeric = $numericPart + 1;
            return 'TRES' . str_pad($nextNumeric, 2, '0', STR_PAD_LEFT);
        } else {
            // First treasurer
            return 'TRES01';
        }
    } catch(Exception $e) {
        throw new Exception("Error generating Treasurer ID: " . $e->getMessage());
    }
}

// Get the active term
$termData = getActiveTerm();

// Get current active officers to check for conflicts
$currentOfficers = getCurrentActiveOfficers();

// Get all current members who could be treasurer or auditor
$members = [];
try {
    $membersQuery = "SELECT MemberID, Name FROM Member WHERE Status = 'Full Member' ORDER BY Name";
    $result = search($membersQuery);
    
    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $members[] = $row;
        }
    }
} catch(Exception $e) {
    $_SESSION['error_message'] = "Error retrieving members: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_officers'])) {
    $termYear = $_POST['term_year'];
    $treasurerMemberID = $_POST['treasurer_id'];
    $auditorMemberID = $_POST['auditor_id'];
    
    // Validate that treasurer and auditor are different people
    if ($treasurerMemberID == $auditorMemberID) {
        $_SESSION['error_message'] = "Treasurer and Auditor must be different individuals.";
    } else {
        try {
            // Begin transaction
            $conn = getConnection();
            $conn->begin_transaction();
            
            // Get member names for both treasurer and auditor
            $memberNameQuery = "SELECT MemberID, Name FROM Member WHERE MemberID IN (?, ?)";
            $memberStmt = $conn->prepare($memberNameQuery);
            $memberStmt->bind_param("ss", $treasurerMemberID, $auditorMemberID);
            $memberStmt->execute();
            $memberResult = $memberStmt->get_result();
            
            $memberNames = [];
            while ($row = $memberResult->fetch_assoc()) {
                $memberNames[$row['MemberID']] = $row['Name'];
            }
            
            // First, deactivate any existing treasurer for this term
            $deactivateTreasurerStmt = $conn->prepare("UPDATE Treasurer SET isActive = 0 WHERE Term = ?");
            $deactivateTreasurerStmt->bind_param("i", $termYear);
            $deactivateTreasurerStmt->execute();
            
            // Generate new Treasurer ID
            $treasurerID = generateTreasurerID($conn);
            
            // Add new treasurer
            $addTreasurerStmt = $conn->prepare("INSERT INTO Treasurer (TreasurerID, Name, Term, isActive, MemberID) VALUES (?, ?, ?, 1, ?)");
            $addTreasurerStmt->bind_param("ssis", $treasurerID, $memberNames[$treasurerMemberID], $termYear, $treasurerMemberID);
            $addTreasurerStmt->execute();
            
            // Deactivate any existing auditor for this term
            $deactivateAuditorStmt = $conn->prepare("UPDATE Auditor SET isActive = 0 WHERE Term = ?");
            $deactivateAuditorStmt->bind_param("i", $termYear);
            $deactivateAuditorStmt->execute();
            
            // Generate new Auditor ID
            $auditorID = generateAuditorID($conn);
            
            // Add new auditor
            $addAuditorStmt = $conn->prepare("INSERT INTO Auditor (AuditorID, Name, Term, isActive, MemberID) VALUES (?, ?, ?, 1, ?)");
            $addAuditorStmt->bind_param("ssis", $auditorID, $memberNames[$auditorMemberID], $termYear, $auditorMemberID);
            $addAuditorStmt->execute();
            
            // Commit transaction
            $conn->commit();
            
            $_SESSION['success_message'] = "Treasurer and Auditor assigned successfully!";
            
            // Clear the new term year from session if it exists
            if (isset($_SESSION['new_term_year'])) {
                unset($_SESSION['new_term_year']);
            }

            // Clear the modal flag from session if it exists
            if (isset($_SESSION['show_officers_modal'])) {
                unset($_SESSION['show_officers_modal']);
            }
            
            // Redirect to the admin home page or officers management page
            header("Location: home-admin.php?officers_added=true");
            exit();
            
        } catch(Exception $e) {
            // Rollback transaction on error
            if (isset($conn)) {
                $conn->rollback();
            }
            $_SESSION['error_message'] = "Error assigning officers: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Treasurer and Auditor</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <!-- Add jQuery and Select2 libraries -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }
 
        .main-container {
            min-height: 100vh;
            background: #f5f7fa;
            display: flex;
            flex-direction: column;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
        }
        
        .welcome-card {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 15px 0 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        
        .page-title {
            font-size: 1.8rem;
            margin: 0;
        }
        
        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: white;
            background-color: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 4px;
            transition: background-color 0.3s;
        }
        
        .back-btn:hover {
            background-color: rgba(255, 255, 255, 0.3);
        }
        
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: 0 auto;
        }
        
        .section-title {
            color: #1e3c72;
            margin-top: 0;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
            border-bottom: 2px solid #f0f0f0;
            padding-bottom: 0.75rem;
        }
        
        .form-group {
            margin-bottom: 1.5rem;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: #333;
        }
        
        .form-group select {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 1rem;
        }
        
        .form-group small {
            display: block;
            margin-top: 0.25rem;
            color: #6c757d;
        }
        
        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .cancel-btn, .save-btn {
            padding: 0.75rem 1.5rem;
            border-radius: 4px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 0.3s;
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
        
        .save-btn {
            background-color: #1e3c72;
            color: white;
            border: none;
        }
        
        .save-btn:hover {
            background-color: #2a5298;
        }
        
        .info-card {
            background-color: #e6f3ff;
            border-left: 4px solid #1e3c72;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
            opacity: 1;
            transition: opacity 0.5s ease-in-out;
        }

        .alert.fade-out {
            opacity: 0;
            transform: translateY(-20px);
        }

        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .no-members-message {
            text-align: center;
            padding: 2rem;
            background-color: #fff3cd;
            color: #856404;
            border: 1px solid #ffeeba;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }
        
        /* Select2 Styling */
        .select2-container {
            width: 100% !important;
        }
        
        .select2-container .select2-selection--single {
            height: 38px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px;
            padding-left: 12px;
            color: #333;
        }
        
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        
        .select2-dropdown {
            border: 1px solid #ddd;
            border-radius: 4px;
        }
        
        .select2-search--dropdown .select2-search__field {
            padding: 8px;
            border: 1px solid #ddd;
        }
        
        .select2-results__option {
            padding: 8px 12px;
        }
        
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #1e3c72;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <div class="welcome-card">
                <h1 class="page-title">Add Treasurer and Auditor</h1>
                <a href="addTerm.php" class="back-btn">
                    <i class="fas fa-arrow-left"></i> Back to Terms
                </a>
            </div>

            <?php if(isset($_SESSION['error_message'])): ?>
                <div id="error-alert" class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['success_message'])): ?>
                <div id="success-alert" class="alert alert-success">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>
            
            <div class="form-container">
                <h2 class="section-title">
                    Assign Officers for Term: 
                    <?php echo isset($termData) ? 'Year ' . htmlspecialchars($termData['year']) : 'No Term Available'; ?>
                </h2>
                
                <div class="info-card">
                    <i class="fas fa-info-circle"></i> 
                    You're assigning key officers for the term. The treasurer will manage all financial aspects, 
                    while the auditor will ensure financial accountability. A member can be assigned as long as they aren't 
                    already serving in the same role in the current active term.
                </div>
                
                <?php if (empty($members)): ?>
                    <div class="no-members-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>No active members available to assign as officers. Please add members first.</p>
                        <a href="addMember.php" class="save-btn" style="display: inline-block; margin-top: 1rem;">Add Members</a>
                    </div>
                <?php elseif (!$termData): ?>
                    <div class="no-members-message">
                        <i class="fas fa-exclamation-triangle"></i>
                        <p>No active term available. Please create or activate a term first.</p>
                        <a href="addTerm.php" class="save-btn" style="display: inline-block; margin-top: 1rem;">Manage Terms</a>
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="term_year" value="<?php echo $termData['year']; ?>">
                        
                        <div class="form-group">
                            <label for="treasurer_id">Select Treasurer</label>
                            <select id="treasurer_id" name="treasurer_id" class="member-select" required>
                                <option value="">-- Select Treasurer --</option>
                                <?php foreach ($members as $member): ?>
                                    <?php 
                                    // Skip if member is already the current treasurer
                                    $isCurrentTreasurer = isset($currentOfficers['treasurerMemberID']) && $currentOfficers['treasurerMemberID'] == $member['MemberID'];
                                    ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo $isCurrentTreasurer ? 'disabled' : ''; ?>>
                                        <?php echo htmlspecialchars($member['Name'] . " (ID: " . $member['MemberID'] . ")"); ?>
                                        <?php echo $isCurrentTreasurer ? ' - Current Treasurer' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>The treasurer manages all financial transactions and records.</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="auditor_id">Select Auditor</label>
                            <select id="auditor_id" name="auditor_id" class="member-select" required>
                                <option value="">-- Select Auditor --</option>
                                <?php foreach ($members as $member): ?>
                                    <?php 
                                    // Skip if member is already the current auditor
                                    $isCurrentAuditor = isset($currentOfficers['auditorMemberID']) && $currentOfficers['auditorMemberID'] == $member['MemberID'];
                                    ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo $isCurrentAuditor ? 'disabled' : ''; ?>>
                                        <?php echo htmlspecialchars($member['Name'] . " (ID: " . $member['MemberID'] . ")"); ?>
                                        <?php echo $isCurrentAuditor ? ' - Current Auditor' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small>The auditor reviews financial records to ensure accuracy and compliance.</small>
                        </div>
                        
                        <div class="form-footer">
                            <a href="addTerm.php" class="cancel-btn">Cancel</a>
                            <button type="submit" name="add_officers" class="save-btn">Assign Officers</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <?php include '../templates/footer.php'; ?>
    </div>
    
    <script>
        // Initialize Select2 for searchable dropdowns
        $(document).ready(function() {
            $('.member-select').select2({
                placeholder: 'Search for a member...',
                allowClear: true,
                width: '100%'
            });
            
            // Client-side validation to ensure treasurer and auditor are different
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const treasurerId = document.getElementById('treasurer_id').value;
                    const auditorId = document.getElementById('auditor_id').value;
                    
                    if (treasurerId === auditorId && treasurerId !== '') {
                        e.preventDefault();
                        alert('Treasurer and Auditor must be different individuals.');
                    }
                });
            }
        });
    </script>
</body>
</html>