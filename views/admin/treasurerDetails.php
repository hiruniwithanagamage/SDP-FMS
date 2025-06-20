<?php
session_start();
require_once "../../config/database.php";

// Check for success message
$successMessage = isset($_SESSION['success_message']) ? $_SESSION['success_message'] : null;

// Clear the session message after retrieving it
if($successMessage) {
    unset($_SESSION['success_message']);
}

// Error message handling
$errorMessage = isset($_SESSION['error_message']) ? $_SESSION['error_message'] : null;
if($errorMessage) {
    unset($_SESSION['error_message']);
}

// Get database connection
$conn = getConnection();

// Get the current active year from static table
$activeYearQuery = "SELECT year FROM static WHERE status = 'active' LIMIT 1";
$activeYearResult = search($activeYearQuery);
$currentActiveYear = null;

if ($activeYearResult && $activeYearResult->num_rows > 0) {
    $activeYearRow = $activeYearResult->fetch_assoc();
    $currentActiveYear = $activeYearRow['year'];
} else {
    // If no active year is found, use the most recent year
    $latestYearQuery = "SELECT year FROM static ORDER BY year DESC LIMIT 1";
    $latestYearResult = search($latestYearQuery);
    
    if ($latestYearResult && $latestYearResult->num_rows > 0) {
        $latestYearRow = $latestYearResult->fetch_assoc();
        $currentActiveYear = $latestYearRow['year'];
    }
}

// Check if there is at least one active treasurer for the current active year
$activeTreasurerQuery = "SELECT COUNT(*) as active_count FROM Treasurer WHERE Term = ? AND isActive = 1";
$activeTreasurerStmt = $conn->prepare($activeTreasurerQuery);
$activeTreasurerStmt->bind_param("i", $currentActiveYear);
$activeTreasurerStmt->execute();
$activeTreasurerResult = $activeTreasurerStmt->get_result();
$activeTreasurerRow = $activeTreasurerResult->fetch_assoc();
$hasActiveTreasurer = ($activeTreasurerRow['active_count'] > 0);

// Fetch all treasurers
$query = "SELECT * FROM Treasurer ORDER BY Term DESC";
$result = search($query);

// Handle Update
if(isset($_POST['update'])) {
    $treasurerId = $_POST['treasurer_id'];
    $name = $_POST['name'];
    $term = $_POST['term'];
    $isActive = $_POST['is_active'];

    // Add validation before updating
    if(empty($name) || !is_numeric($term) || ($isActive != '0' && $isActive != '1')) {
        $_SESSION['error_message'] = "Invalid input data";
        header("Location: " . $_SERVER['PHP_SELF']);
        exit();
    }

    try {
        // Get connection
        $conn = getConnection();
        
        // Get the current treasurer's data
        $currentTreasurerQuery = "SELECT Term, isActive FROM Treasurer WHERE TreasurerID = ?";
        $currentTreasurerStmt = $conn->prepare($currentTreasurerQuery);
        $currentTreasurerStmt->bind_param("s", $treasurerId);
        $currentTreasurerStmt->execute();
        $currentTreasurerResult = $currentTreasurerStmt->get_result();
        $currentTreasurerRow = $currentTreasurerResult->fetch_assoc();
        $currentTerm = $currentTreasurerRow['Term'];
        $currentIsActive = $currentTreasurerRow['isActive'];
        
        // Check if user trying to set this treasurer as active
        if($isActive == '1') {
            // Check if any other treasurer in the same term is already active
            $checkQuery = "SELECT COUNT(*) as active_count FROM Treasurer 
                           WHERE Term = ? AND isActive = 1 AND TreasurerID != ?";
            $checkStmt = $conn->prepare($checkQuery);
            $checkStmt->bind_param("is", $term, $treasurerId);
            $checkStmt->execute();
            $checkResult = $checkStmt->get_result();
            $row = $checkResult->fetch_assoc();
            
            if($row['active_count'] > 0) {
                $_SESSION['error_message'] = "Only one treasurer can be active per term. Please deactivate the currently active treasurer first.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } else if($isActive == '0' && $currentIsActive == 1 && $currentTerm == $currentActiveYear) {
            // If setting to inactive and treasurer is currently active in the current active year
            // Check if there will still be at least one active treasurer in the current active year
            $activeInCurrentYearQuery = "SELECT COUNT(*) as active_count FROM Treasurer 
                                         WHERE Term = ? AND isActive = 1 AND TreasurerID != ?";
            $activeStmt = $conn->prepare($activeInCurrentYearQuery);
            $activeStmt->bind_param("is", $currentActiveYear, $treasurerId);
            $activeStmt->execute();
            $activeResult = $activeStmt->get_result();
            $activeRow = $activeResult->fetch_assoc();
            
            if($activeRow['active_count'] == 0) {
                $_SESSION['error_message'] = "Cannot update. There must be at least one active treasurer for the current active year (" . $currentActiveYear . ").";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        } else if($term != $currentTerm && $currentTerm == $currentActiveYear && $currentIsActive == 1) {
            // If changing term of an active treasurer in the current active year
            // Check if there will still be at least one active treasurer in current active year
            $activeInCurrentYearQuery = "SELECT COUNT(*) as active_count FROM Treasurer 
                                         WHERE Term = ? AND isActive = 1 AND TreasurerID != ?";
            $activeStmt = $conn->prepare($activeInCurrentYearQuery);
            $activeStmt->bind_param("is", $currentActiveYear, $treasurerId);
            $activeStmt->execute();
            $activeResult = $activeStmt->get_result();
            $activeRow = $activeResult->fetch_assoc();
            
            if($activeRow['active_count'] == 0) {
                $_SESSION['error_message'] = "Cannot update. Changing the term would leave the current active year (" . $currentActiveYear . ") without an active treasurer.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        }
        
        // Use prepared statement for update
        $stmt = $conn->prepare("UPDATE Treasurer SET Name = ?, Term = ?, isActive = ? WHERE TreasurerID = ?");
        $term = intval($term);
        $isActive = intval($isActive);
        $stmt->bind_param("siis", $name, $term, $isActive, $treasurerId);
        $stmt->execute();
        
        // Check if update was successful
        if($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Treasurer updated successfully";
        } else {
            $_SESSION['success_message'] = "No changes were made";
        }
        $stmt->close();
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error updating treasurer: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Handle Delete
if(isset($_POST['delete'])) {
    $treasurerId = $_POST['treasurer_id'];
    
    try {
        // Get connection
        $conn = getConnection();
        
        // Check if this treasurer is active and if they're the only active treasurer in the current active year
        $checkQuery = "SELECT Term, isActive FROM Treasurer WHERE TreasurerID = ?";
        $checkStmt = $conn->prepare($checkQuery);
        $checkStmt->bind_param("s", $treasurerId);
        $checkStmt->execute();
        $checkResult = $checkStmt->get_result();
        $treasurerData = $checkResult->fetch_assoc();
        
        // Only check if:
        // 1. This treasurer is active
        // 2. This treasurer belongs to the current active year
        // 3. They're the only active treasurer in the current active year
        if ($treasurerData['isActive'] == 1 && $treasurerData['Term'] == $currentActiveYear) {
            $activeCountQuery = "SELECT COUNT(*) as active_count FROM Treasurer 
                               WHERE Term = ? AND isActive = 1 AND TreasurerID != ?";
            $activeCountStmt = $conn->prepare($activeCountQuery);
            $activeCountStmt->bind_param("is", $currentActiveYear, $treasurerId);
            $activeCountStmt->execute();
            $activeCountResult = $activeCountStmt->get_result();
            $activeCountRow = $activeCountResult->fetch_assoc();
            
            if($activeCountRow['active_count'] == 0) {
                $_SESSION['error_message'] = "Cannot delete the only active treasurer for the current active term (" . $currentActiveYear . "). Please activate another treasurer for this year first.";
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        }
        
        // Use prepared statement for delete
        $stmt = $conn->prepare("DELETE FROM Treasurer WHERE TreasurerID = ? AND isActive = 0");
        $stmt->bind_param("s", $treasurerId);
        $stmt->execute();
        
        // Check if delete was successful
        if($stmt->affected_rows > 0) {
            $_SESSION['success_message'] = "Treasurer deleted successfully";
        } else {
            $_SESSION['error_message'] = "Treasurer not found";
        }
        $stmt->close();
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Cannot delete this treasurer. They may have associated records: " . $e->getMessage();
    }
    
    header("Location: " . $_SERVER['PHP_SELF']);
    exit();
}

// Get available terms from the static table for the dropdown
$termsQuery = "SELECT DISTINCT year FROM static ORDER BY year DESC";
$termsResult = search($termsQuery);
$availableTerms = [];
if ($termsResult && $termsResult->num_rows > 0) {
    while($row = $termsResult->fetch_assoc()) {
        $availableTerms[] = $row['year'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Treasurers</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background-color: #f5f7fa;
        }

        .container {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 2rem;
            background-color: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .header-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        h1 {
            color: #1a237e;
            margin: 0;
        }

        .add-btn {
            background-color: #1a237e;
            color: white;
            padding: 0.7rem 1.5rem;
            border-radius: 4px;
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.3s;
        }

        .add-btn:hover {
            background-color: #0d1757;
        }

        .table-responsive {
            overflow-x: auto;
        }

        .treasurer-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 2rem;
        }

        .treasurer-table th {
            background-color: #f5f7fa;
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: #1a237e;
            border-bottom: 2px solid #e0e0e0;
        }

        .treasurer-table td {
            padding: 1rem;
            border-bottom: 1px solid #e0e0e0;
        }

        .treasurer-table tr:hover {
            background-color: #f5f7fa;
        }

        .active-year-row {
            background-color: #f9fbe7 !important;
        }

        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }

        .action-btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            transition: background-color 0.3s;
        }

        .edit-btn {
            background-color: #e3f2fd;
            color: #0d47a1;
        }

        .edit-btn:hover {
            background-color: #bbdefb;
        }

        .delete-btn {
            background-color: #ffebee;
            color: #c62828;
        }

        .delete-btn:hover {
            background-color: #ffcdd2;
        }

        /* Status Badge Styles */
        .status-badge {
            display: inline-block;
            padding: 0.3rem 0.7rem;
            border-radius: 50px;
            font-size: 0.8rem;
            font-weight: 500;
        }

        .status-active {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .status-inactive {
            background-color: #ffebee;
            color: #c62828;
        }

        /* Modal Styles */
        .modal, .delete-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.4);
        }

        .modal-content, .delete-modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
            width: 80%;
            max-width: 500px;
        }

        .modal-content h2, .delete-modal-content h2 {
            color: #1a237e;
            margin-top: 0;
            margin-bottom: 1.5rem;
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 1.5rem;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: #1a237e;
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

        .form-group input, .form-group select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s;
        }

        .form-group input:focus, .form-group select:focus {
            border-color: #1a237e;
            outline: none;
        }

        .modal-footer {
            margin-top: 2rem;
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
        }

        .save-btn, .confirm-delete-btn {
            background-color: #1a237e;
            color: white;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background-color 0.3s;
        }

        .save-btn:hover {
            background-color: #0d1757;
        }

        .cancel-btn {
            background-color: #e0e0e0;
            color: #333;
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: background-color 0.3s;
        }

        .cancel-btn:hover {
            background-color: #bdbdbd;
        }

        .delete-modal-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
        }

        .confirm-delete-btn {
            background-color: #c62828;
        }

        .confirm-delete-btn:hover {
            background-color: #b71c1c;
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .alert-danger {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .alert-info {
            background-color: #e3f2fd;
            color: #0d47a1;
            border: 1px solid #bbdefb;
        }

        .alert-warning {
            background-color: #fffde7;
            color: #f57f17;
            border: 1px solid #fff9c4;
        }

        /* Term dropdown styling */
        .term-select {
            max-height: 200px;
            overflow-y: auto;
        }

        /* Current year highlight */
        .current-year {
            font-weight: bold;
            color: #1a237e;
        }

        /* Info box styling */
        .info-box {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 4px;
        }

        .info-box-title {
            font-weight: 600;
            color: #2e7d32;
            margin-bottom: 0.5rem;
        }

        .info-box ul {
            margin: 0.5rem 0;
            padding-left: 1.5rem;
        }

        .info-box li {
            margin-bottom: 0.3rem;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-admin.php'; ?>
        
        <div class="container">
            <div class="header-section">
                <h1>Manage Treasurers</h1>
                <a href="addTreasurer.php" class="add-btn">
                    <i class="fas fa-plus"></i> Add Treasurer
                </a>
            </div>

            <?php if($successMessage): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($successMessage); ?>
                </div>
            <?php endif; ?>

            <?php if($errorMessage): ?>
                <div class="alert alert-danger">
                    <?php echo htmlspecialchars($errorMessage); ?>
                </div>
            <?php endif; ?>
            
            <!-- Information Box -->
            <div class="info-box">
                <div class="info-box-title">Treasurer Management Rules</div>
                <ul>
                    <li>Current active term: <span class="current-year"><?php echo htmlspecialchars($currentActiveYear); ?></span></li>
                    <li>Only one treasurer can be active per term</li>
                    <li>There must be <strong>at least one active treasurer</strong> for the current active term</li>
                    <?php if(!$hasActiveTreasurer): ?>
                    <li class="alert-danger" style="padding: 5px; list-style: none; margin-top: 5px;">
                        <strong>Warning:</strong> There is currently no active treasurer for the current active term. Please activate one.
                    </li>
                    <?php endif; ?>
                </ul>
            </div>

            <div class="table-responsive">
                <table class="treasurer-table">
                    <thead>
                        <tr>
                            <th>Treasurer ID</th>
                            <th>Name</th>
                            <th>Term</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        if ($result->num_rows > 0) {
                            while($row = $result->fetch_assoc()): 
                                $isCurrentYear = ($row['Term'] == $currentActiveYear);
                        ?>
                        <tr class="<?php echo $isCurrentYear ? 'active-year-row' : ''; ?>">
                            <td><?php echo htmlspecialchars($row['TreasurerID']); ?></td>
                            <td>
                                <?php echo htmlspecialchars($row['Name']); ?>
                                <?php if($isCurrentYear): ?><span class="current-year"> (Current Year)</span><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($row['Term']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $row['isActive'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $row['isActive'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <button class="action-btn edit-btn" onclick="openEditModal('<?php echo htmlspecialchars($row['TreasurerID']); ?>', '<?php echo htmlspecialchars(addslashes($row['Name'])); ?>', '<?php echo htmlspecialchars($row['Term']); ?>', '<?php echo htmlspecialchars($row['isActive']); ?>')">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="action-btn delete-btn" onclick="openDeleteModal('<?php echo htmlspecialchars($row['TreasurerID']); ?>', '<?php echo ($row['Term'] == $currentActiveYear && $row['isActive'] == 1) ? '1' : '0'; ?>')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php 
                            endwhile;
                        } else {
                        ?>
                        <tr>
                            <td colspan="5" style="text-align: center;">No treasurers found</td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Edit Modal -->
        <div id="editModal" class="modal">
            <div class="modal-content">
                <span class="close" onclick="closeModal()">&times;</span>
                <h2>Edit Treasurer</h2>
                <form id="editForm" method="POST">
                    <input type="hidden" id="edit_treasurer_id" name="treasurer_id">
                    <input type="hidden" id="is_current_active_year" value="<?php echo $currentActiveYear; ?>">
                    
                    <div class="form-group">
                        <label for="edit_name">Name</label>
                        <input type="text" id="edit_name" name="name" required>
                    </div>

                    <div class="form-group">
                        <label for="edit_term">Term</label>
                        <select id="edit_term" name="term" required class="term-select">
                            <?php foreach ($availableTerms as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo ($year == $currentActiveYear) ? 'class="current-year"' : ''; ?>>
                                    <?php echo $year; ?><?php echo ($year == $currentActiveYear) ? ' (Current Active Term)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="edit_status">Status</label>
                        <select id="edit_status" name="is_active" required>
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>

                    <div id="edit_warning" class="alert alert-warning" style="display: none;">
                        <strong>Warning:</strong> <span id="warning_message"></span>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="cancel-btn" onclick="closeModal()">Cancel</button>
                        <button type="submit" name="update" class="save-btn">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Delete Confirmation Modal -->
        <div id="deleteModal" class="delete-modal">
            <div class="delete-modal-content">
                <h2>Confirm Delete</h2>
                <p>Are you sure you want to delete this treasurer? This action cannot be undone.</p>
                <div id="delete_warning" class="alert alert-warning" style="display: none;">
                    <strong>Warning:</strong> This is an active treasurer for the current active term. 
                    Make sure there is another active treasurer for this year before deleting.
                </div>
                <form method="POST" id="deleteForm">
                    <input type="hidden" id="delete_treasurer_id" name="treasurer_id">
                    <div class="delete-modal-buttons">
                        <button type="button" class="cancel-btn" onclick="closeDeleteModal()">Cancel</button>
                        <button type="submit" name="delete" class="confirm-delete-btn">Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function openEditModal(id, name, term, isActive) {
            document.getElementById('editModal').style.display = 'block';
            document.getElementById('edit_treasurer_id').value = id;
            document.getElementById('edit_name').value = name;
            
            // Set the term dropdown value
            const termSelect = document.getElementById('edit_term');
            
            // First, check if the term exists in the dropdown
            let termExists = false;
            for (let i = 0; i < termSelect.options.length; i++) {
                if (termSelect.options[i].value == term) {
                    termSelect.selectedIndex = i;
                    termExists = true;
                    break;
                }
            }
            
            // If the term doesn't exist in the dropdown, add it
            if (!termExists) {
                const option = new Option(term, term);
                termSelect.add(option);
                termSelect.value = term;
            }
            
            document.getElementById('edit_status').value = isActive;

            // Show warning if needed
            const currentActiveYear = document.getElementById('is_current_active_year').value;
            const warningElement = document.getElementById('edit_warning');
            const warningMessage = document.getElementById('warning_message');
            
            // Only show the warning if this is an active treasurer for the current year
            if (term == currentActiveYear && isActive == 1) {
                warningMessage.innerText = "This is an active treasurer for the current active term. Changing status or term may require activating another treasurer for the current year.";
                warningElement.style.display = 'block';
            } else {
                warningElement.style.display = 'none';
            }
        }

        function closeModal() {
            document.getElementById('editModal').style.display = 'none';
        }

        function openDeleteModal(id, isActiveCurrentYear) {
            document.getElementById('deleteModal').style.display = 'block';
            document.getElementById('delete_treasurer_id').value = id;
            
            // Show warning if this is an active treasurer for the current year
            const warningElement = document.getElementById('delete_warning');
            if (isActiveCurrentYear === '1') {
                warningElement.style.display = 'block';
            } else {
                warningElement.style.display = 'none';
            }
        }

        function closeDeleteModal() {
            document.getElementById('deleteModal').style.display = 'none';
        }

        // Close modals when clicking outside
        window.onclick = function(event) {
            const editModal = document.getElementById('editModal');
            const deleteModal = document.getElementById('deleteModal');
            
            if (event.target == editModal) {
                closeModal();
            }
            
            if (event.target == deleteModal) {
                closeDeleteModal();
            }
        }
    </script>
</body>
</html>