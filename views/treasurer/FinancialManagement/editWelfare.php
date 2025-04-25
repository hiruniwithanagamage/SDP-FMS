<?php
$isPopup = isset($_GET['popup']) && $_GET['popup'] === 'true';
session_start();
require_once "../../../config/database.php";

// Check if ID parameter exists
if (!isset($_GET['id']) || empty($_GET['id'])) {
    $_SESSION['error_message'] = "No welfare ID provided";
    header("Location: deathWelfare.php");
    exit();
}

$welfareID = $_GET['id'];

// Function to get welfare details
function getWelfareDetails($welfareID) {
    $conn = getConnection();
    $stmt = $conn->prepare("
        SELECT 
            dw.WelfareID, 
            dw.Amount, 
            dw.Date, 
            dw.Term, 
            dw.Relationship, 
            dw.Status,
            dw.Member_MemberID,
            m.Name as MemberName
        FROM DeathWelfare dw
        JOIN Member m ON dw.Member_MemberID = m.MemberID
        WHERE dw.WelfareID = ?
    ");
    
    $stmt->bind_param("s", $welfareID);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        return null;
    }
    
    return $result->fetch_assoc();
}

// Function to get all members using prepared statement
function getAllMembers() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT MemberID, Name FROM Member ORDER BY Name");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result;
}

// Function to get welfare settings using prepared statement
function getWelfareSettings() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT death_welfare FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    return $result->fetch_assoc();
}

// Function to get current term/year using prepared statement
function getCurrentTerm() {
    $conn = getConnection();
    $stmt = $conn->prepare("SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1");
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

// Get welfare details
$welfare = getWelfareDetails($welfareID);
if (!$welfare) {
    $_SESSION['error_message'] = "Welfare record not found";
    header("Location: deathWelfare.php");
    exit();
}

// Get all members for the dropdown
$allMembers = getAllMembers();
$currentTerm = getCurrentTerm();
$welfareSettings = getWelfareSettings();

// Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Get form data
    $memberID = $_POST['member_id'];
    $date = $_POST['date'];
    $relationship = $_POST['relationship'];
    $status = $_POST['status'];
    
    // Use the fixed standard welfare amount - not allowing it to be changed
    $amount = $welfareSettings['death_welfare'];
    
    try {
        $conn = getConnection();
        
        // Start transaction
        $conn->begin_transaction();
        
        // Update welfare record
        $stmt = $conn->prepare("
            UPDATE DeathWelfare SET 
                Member_MemberID = ?,
                Amount = ?,
                Date = ?,
                Relationship = ?,
                Status = ?,
                Term = ?
            WHERE WelfareID = ?
        ");
        
        $stmt->bind_param("sssssis", 
            $memberID, 
            $amount, 
            $date, 
            $relationship,
            $status,
            $currentTerm,
            $welfareID
        );
        
        $stmt->execute();
        
        // Commit transaction
        $conn->commit();
        
        // Set success message
        $_SESSION['success_message'] = "Welfare claim #$welfareID successfully updated";
        
        // Handle redirection based on popup mode after ALL database operations are complete
        if (!$isPopup) {
            header("Location: deathWelfare.php");
            exit();
        }
        // If it's popup mode, we'll continue rendering the page with a success message
        // and add JavaScript to refresh the parent later
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        $_SESSION['error_message'] = "Error updating welfare claim: " . $e->getMessage();
    }
}

// Welfare status options
$welfareStatus = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected'
];

// Relationship options
$relationships = [
    'dog' => 'Dog',
    'mother' => 'Mother',
    'father' => 'Father',
    'child' => 'Child',
    'sibling' => 'Sibling',
    'self' => 'Self'
];

// Now output the HTML based on popup mode
if ($isPopup): ?>
    <!-- Simplified header for popup mode -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Welfare Claim</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <style>
            body { 
                padding: 0; 
                margin: 0; 
                background: white; 
                font-family: Arial, sans-serif;
            }
            .container { 
                padding: 10px; 
            }
            .header-card { 
                display: none; 
            }
            .main-container { 
                padding: 0; 
            }
            .form-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 20px;
                width: 100%;
                margin: 10px auto;
            }
            .form-title {
                color: #1e3c72;
                margin-bottom: 20px;
                text-align: center;
                font-size: 1.5rem;
            }
            .form-group {
                margin-bottom: 15px;
            }
            .form-group label {
                display: block;
                margin-bottom: 6px;
                font-weight: 600;
                color: #333;
            }
            .form-control {
                width: 100%;
                padding: 8px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
                transition: border-color 0.3s;
                box-sizing: border-box;
            }
            .form-control:disabled {
                background-color: #f5f5f5;
                cursor: not-allowed;
            }
            .form-control:focus {
                border-color: #1e3c72;
                outline: none;
                box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
            }
            .form-row {
                display: flex;
                gap: 15px;
            }
            .form-row .form-group {
                flex: 1;
            }
            .btn-container {
                display: flex;
                justify-content: flex-end;
                margin-top: 20px;
            }
            .btn {
                min-width: 120px;
                padding: 10px 20px;
                border: none;
                border-radius: 4px;
                font-size: 14px;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .btn-primary {
                background-color: #1e3c72;
                color: white;
                height: 40px;
            }
            .btn-primary:hover {
                background-color: #16305c;
            }
            .btn-secondary {
                background-color: #e0e0e0;
                color: #333;
                margin-right: 30px;
                text-align: center;
                font-weight: bold;
                display: block;
            }

            .btn-secondary:hover {
                background-color: #5a6268;
            }
            .member-info {
                background-color: #f9f9f9;
                padding: 12px;
                border-radius: 5px;
                margin-bottom: 15px;
                font-size: 14px;
            }
            .member-info-title {
                font-weight: 600;
                margin-bottom: 8px;
                color: #1e3c72;
            }
            .alert {
                padding: 10px 15px;
                margin-bottom: 15px;
                border-radius: 4px;
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
            .status-pending {
                background-color: #fff8e8;
                color: #f6a609;
            }
            .status-approved {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            .status-rejected {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            .standard-value {
                font-size: 12px;
                color: #666;
                margin-top: 5px;
                display: block;
            }
        </style>
    </head>
    <body>
        <div class="container">
<?php else: ?>
    <!-- Regular header for standalone page -->
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Edit Welfare Claim</title>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <link rel="stylesheet" href="../../../assets/css/adminDetails.css">
        <link rel="stylesheet" href="../../../assets/css/financialManagement.css">
        <link rel="stylesheet" href="../../../assets/css/alert.css">
        <script src="../../../assets/js/alertHandler.js"></script>
        <style>
            .form-container {
                background-color: #fff;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                padding: 30px;
                max-width: 800px;
                margin: 20px auto;
            }

            .form-title {
                color: #1e3c72;
                margin-bottom: 25px;
                text-align: center;
            }

            .form-group {
                margin-bottom: 20px;
            }

            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #333;
            }

            .form-control {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 16px;
                transition: border-color 0.3s;
            }

            .form-control:disabled {
                background-color: #f5f5f5;
                cursor: not-allowed;
            }

            .form-control:focus {
                border-color: #1e3c72;
                outline: none;
                box-shadow: 0 0 0 2px rgba(30, 60, 114, 0.2);
            }

            .form-row {
                display: flex;
                gap: 20px;
            }

            .form-row .form-group {
                flex: 1;
            }

            .btn-container {
                display: flex;
                justify-content: space-between;
                margin-top: 30px;
            }

            .btn {
                padding: 12px 24px;
                border: none;
                border-radius: 4px;
                font-size: 16px;
                cursor: pointer;
                transition: background-color 0.3s;
            }

            .btn-primary {
                background-color: #1e3c72;
                color: white;
            }

            .btn-primary:hover {
                background-color: #16305c;
            }

            .btn-secondary {
                background-color: #e0e0e0;
                color: #333;
            }

            .btn-secondary:hover {
                background-color: #5a6268;
            }

            .member-info {
                background-color: #f9f9f9;
                padding: 15px;
                border-radius: 5px;
                margin-bottom: 20px;
            }

            .member-info-title {
                font-weight: 600;
                margin-bottom: 10px;
                color: #1e3c72;
            }
            
            .status-badge {
                display: inline-block;
                padding: 5px 10px;
                border-radius: 15px;
                font-size: 0.8rem;
                font-weight: bold;
            }
            
            .status-pending {
                background-color: #fff8e8;
                color: #f6a609;
            }
            
            .status-approved {
                background-color: #c2f1cd;
                color: rgb(25, 151, 10);
            }
            
            .status-rejected {
                background-color: #e2bcc0;
                color: rgb(234, 59, 59);
            }
            
            .standard-value {
                font-size: 14px;
                color: #666;
                margin-top: 5px;
                display: block;
            }
        </style>
    </head>
    <body>
        <div class="main-container">
            <?php include '../../templates/navbar-treasurer.php'; ?>
            <div class="container">
                <div class="header-card">
                    <h1>Edit Welfare Claim</h1>
                    <a href="deathWelfare.php" class="btn-secondary btn">
                        <i class="fas fa-arrow-left"></i> Back to Welfare Claims
                    </a>
                </div>
<?php endif; ?>

            <!-- Generate alerts -->
            <div class="alerts-container">
                <?php if(isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <?php 
                            echo $_SESSION['success_message'];
                            unset($_SESSION['success_message']);
                        ?>
                    </div>
                <?php endif; ?>

                <?php if(isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger">
                        <?php 
                            echo $_SESSION['error_message'];
                            unset($_SESSION['error_message']);
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="form-container">
                <h2 class="form-title">Edit Welfare Claim #<?php echo htmlspecialchars($welfareID); ?></h2>
                
                <div class="member-info">
                    <div class="member-info-title">Current Welfare Claim Information</div>
                    <p>Member ID: <?php echo htmlspecialchars($welfare['Member_MemberID']); ?></p>
                    <p>Member Name: <?php echo htmlspecialchars($welfare['MemberName']); ?></p>
                    <p>Status: <span class="status-badge status-<?php echo $welfare['Status']; ?>"><?php echo ucfirst($welfare['Status']); ?></span></p>
                </div>

                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="welfare_id">Welfare ID</label>
                            <input type="text" id="welfare_id" class="form-control" value="<?php echo htmlspecialchars($welfareID); ?>" disabled>
                        </div>
                        <div class="form-group">
                            <label for="member_id">Member</label>
                            <select id="member_id" name="member_id" class="form-control" required>
                                <?php while($member = $allMembers->fetch_assoc()): ?>
                                    <option value="<?php echo $member['MemberID']; ?>" <?php echo ($member['MemberID'] == $welfare['Member_MemberID']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($member['MemberID'] . ' - ' . $member['Name']); ?>
                                    </option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="amount">Welfare Amount (Rs.)</label>
                            <input type="text" id="amount" class="form-control" value="<?php echo number_format($welfareSettings['death_welfare'], 2); ?>" disabled>
                            <span class="standard-value">Standard welfare amount set by organization policy</span>
                        </div>
                        <div class="form-group">
                            <label for="date">Claim Date</label>
                            <input type="date" id="date" name="date" class="form-control" value="<?php echo date('Y-m-d', strtotime($welfare['Date'])); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="relationship">Relationship</label>
                            <select id="relationship" name="relationship" class="form-control" required>
                                <?php foreach($relationships as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $welfare['Relationship']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="status">Status</label>
                            <select id="status" name="status" class="form-control" required>
                                <?php foreach($welfareStatus as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($value == $welfare['Status']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="btn-container">
                        <?php if ($isPopup): ?>
                            <button type="button" class="btn btn-secondary" onclick="window.parent.closeEditModal()">Cancel</button>
                        <?php else: ?>
                            <a href="deathWelfare.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                        <button type="submit" class="btn btn-primary">Update Welfare Claim</button>
                    </div>
                </form>
            </div>

<?php if ($isPopup): ?>
    </div>
    
    <?php if ($_SERVER["REQUEST_METHOD"] == "POST" && !isset($_SESSION['error_message'])): ?>
<script>
    // If form was submitted successfully in popup mode, pass message to parent
    window.parent.showAlert('success', 'Welfare claim #<?php echo $welfareID; ?> successfully updated');
    window.parent.closeEditModal();
    // Don't reload the entire page as it will lose the alert
</script>
<?php elseif ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_SESSION['error_message'])): ?>
<script>
    // If form had errors, pass error message to parent
    window.parent.showAlert('error', '<?php echo addslashes($_SESSION['error_message']); ?>');
</script>
<?php unset($_SESSION['error_message']); ?>
<?php endif; ?>
    
</body>
</html>
<?php else: ?>
        </div>
        <?php include '../../templates/footer.php'; ?>
    </div>
</body>
</html>
<?php endif; ?>

<script>
    // Form validation
    document.querySelector('form').addEventListener('submit', function(e) {
        const date = new Date(document.getElementById('date').value);
        const today = new Date();
        
        if (date > today) {
            e.preventDefault();
            alert('Claim date cannot be in the future.');
        }
    });
</script>