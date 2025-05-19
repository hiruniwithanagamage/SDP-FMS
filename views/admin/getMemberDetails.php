<?php
session_start();
require_once "../../config/database.php";

// Check if user is logged in and is admin
if (!isset($_SESSION["u"]) || $_SESSION["role"] !== "admin") {
    echo '<div class="error">Unauthorized access</div>';
    exit();
}

// Check if ID is provided
if (!isset($_GET['id'])) {
    echo '<div class="error">Member ID is required</div>';
    exit();
}

// Get member ID and sanitize it
$conn = getConnection();
$memberId = $conn->real_escape_string($_GET['id']);

// Fetch member details
$query = "SELECT * FROM Member WHERE MemberID = ?";
$stmt = $conn->prepare($query);
$stmt->bind_param("s", $memberId);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $member = $result->fetch_assoc();
    
    // Determine status class for badge styling
    $statusClass = '';
    switch ($member['Status']) {
        case 'Full Member':
            $statusClass = 'active';
            break;
        case 'Pending':
            $statusClass = 'pending';
            break;
        case 'Left':
            $statusClass = 'left';
            break;
        default:
            $statusClass = '';
    }
    
    // Check if member has a photo
    $hasPhoto = false;
    $photoPath = '';
    
    if (!empty($member['Image'])) {
        $uploadPath = "../../uploads/profilePictures/" . $member['Image'];
        if (file_exists($uploadPath)) {
            $hasPhoto = true;
            $photoPath = $uploadPath;
        }
    }

    // Format date properly
    $joinedDate = !empty($member['Joined_Date']) ? date('Y-m-d', strtotime($member['Joined_Date'])) : 'Not available';
    $dob = !empty($member['DoB']) ? date('Y-m-d', strtotime($member['DoB'])) : 'Not available';
    
    // Generate HTML content
    ?>
    <div class="member-details">
        <div class="member-photo">
            <?php if ($hasPhoto): ?>
                <img src="<?php echo $photoPath; ?>" alt="Profile Photo" style="max-width: 200px; max-height: 200px; border-radius: 5px;">
            <?php else: ?>
                <div class="no-photo">No photo available</div>
            <?php endif; ?>
        </div>
        <div class="member-info">
            <div class="detail-row">
                <div class="detail-label">Member ID:</div>
                <div class="detail-value"><?php echo htmlspecialchars($member['MemberID']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Name:</div>
                <div class="detail-value"><?php echo htmlspecialchars($member['Name']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">NIC:</div>
                <div class="detail-value"><?php echo htmlspecialchars($member['NIC']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Date of Birth:</div>
                <div class="detail-value"><?php echo $dob; ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Address:</div>
                <div class="detail-value"><?php echo htmlspecialchars($member['Address']); ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Contact Number:</div>
                <div class="detail-value"><?php echo !empty($member['Mobile_Number']) ? htmlspecialchars($member['Mobile_Number']) : 'Not provided'; ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Family Members:</div>
                <div class="detail-value"><?php echo isset($member['No_of_Family_Members']) ? htmlspecialchars($member['No_of_Family_Members']) : '0'; ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Other Members:</div>
                <div class="detail-value"><?php echo isset($member['Other_Members']) ? htmlspecialchars($member['Other_Members']) : '0'; ?></div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Status:</div>
                <div class="detail-value">
                    <span class="status-badge status-<?php echo $statusClass; ?>">
                        <?php echo htmlspecialchars($member['Status']); ?>
                    </span>
                </div>
            </div>
            <div class="detail-row">
                <div class="detail-label">Joined Date:</div>
                <div class="detail-value"><?php echo $joinedDate; ?></div>
            </div>
        </div>
    </div>
    <div class="action-buttons-container" style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
        <button class="action-btn edit-btn" onclick="closeViewModal(); openEditModal(
            '<?php echo htmlspecialchars($member['MemberID']); ?>', 
            '<?php echo htmlspecialchars(addslashes($member['Name'])); ?>', 
            '<?php echo htmlspecialchars($member['NIC']); ?>', 
            '<?php echo htmlspecialchars($member['DoB']); ?>', 
            '<?php echo htmlspecialchars(addslashes($member['Address'])); ?>', 
            '<?php echo htmlspecialchars($member['Mobile_Number'] ?? ''); ?>', 
            '<?php echo htmlspecialchars($member['No_of_Family_Members'] ?? 0); ?>', 
            '<?php echo htmlspecialchars($member['Other_Members'] ?? 0); ?>', 
            '<?php echo htmlspecialchars($member['Status']); ?>'
        )">
            <i class="fas fa-edit"></i> Edit Member
        </button>
        <button class="action-btn delete-btn" onclick="closeViewModal(); openDeleteModal('<?php echo htmlspecialchars($member['MemberID']); ?>')">
            <i class="fas fa-trash"></i> Delete Member
        </button>
    </div>
    <?php
} else {
    echo '<div class="error">Member not found</div>';
}
$conn->close();
?>