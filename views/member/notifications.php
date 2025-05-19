<?php
session_start();
require_once "../../config/database.php";

// Check if user is logged in
if (!isset($_SESSION['u']) || !isset($_SESSION['u']['Member_MemberID'])) {
    // Redirect to login if not logged in
    header("Location: ../../index.php");
    exit;
}

$memberID = $_SESSION['u']['Member_MemberID'];
$conn = getConnection();

// First, auto-mark older notifications (older than 30 days) as "Older"
$thirtyDaysAgo = date('Y-m-d H:i:s', strtotime('-30 days'));
$updateOlderQuery = "UPDATE ChangeLog 
                     SET Status = 'Older' 
                     WHERE MemberID = ? 
                     AND Status = 'Not Read'
                     AND ChangeDate < ?";
$updateOlderStmt = $conn->prepare($updateOlderQuery);
$updateOlderStmt->bind_param("ss", $memberID, $thirtyDaysAgo);
$updateOlderStmt->execute();

// Get filter parameter (default to showing all notifications)
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
$filterWhere = "MemberID = ?";
$filterParams = array($memberID);

// Apply filter to query
if ($filter === 'unread') {
    $filterWhere .= " AND Status = 'Not Read'";
} elseif ($filter === 'read') {
    $filterWhere .= " AND Status = 'Read'";
} elseif ($filter === 'older') {
    $filterWhere .= " AND Status = 'Older'";
}

// Pagination settings
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10; // Notifications per page
$offset = ($page - 1) * $limit;

// Get total number of notifications for this member with filter applied
$countQuery = "SELECT COUNT(*) as total FROM ChangeLog WHERE $filterWhere";
$countStmt = $conn->prepare($countQuery);
// Bind parameters based on the filter
if (count($filterParams) === 1) {
    $countStmt->bind_param("s", $filterParams[0]);
} elseif (count($filterParams) === 2) {
    $countStmt->bind_param("ss", $filterParams[0], $filterParams[1]);
}
$countStmt->execute();
$countResult = $countStmt->get_result();
$totalNotifications = $countResult->fetch_assoc()['total'];
$totalPages = ceil($totalNotifications / $limit);

// Get notifications with pagination and filter
$notificationsQuery = "SELECT 
                     LogID,
                     RecordType,
                     RecordID,
                     ChangeDetails,
                     Status,
                     DATE_FORMAT(ChangeDate, '%Y-%m-%d %H:%i') as FormattedDate
                     FROM ChangeLog 
                     WHERE $filterWhere 
                     ORDER BY ChangeDate DESC 
                     LIMIT ? OFFSET ?";
$stmt = $conn->prepare($notificationsQuery);
// Bind parameters based on the filter
if (count($filterParams) === 1) {
    $stmt->bind_param("sii", $filterParams[0], $limit, $offset);
} elseif (count($filterParams) === 2) {
    $stmt->bind_param("ssii", $filterParams[0], $filterParams[1], $limit, $offset);
}
$stmt->execute();
$result = $stmt->get_result();
$notifications = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $notifications[] = $row;
    }
}

// Mark all notifications as read if requested
if (isset($_GET['markAllRead']) && $_GET['markAllRead'] == 1) {
    $updateQuery = "UPDATE ChangeLog SET Status = 'Read' WHERE MemberID = ? AND Status = 'Not Read'";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("s", $memberID);
    if ($updateStmt->execute()) {
        $_SESSION['success_message'] = "All notifications marked as read";
    } else {
        $_SESSION['error_message'] = "Failed to update notifications";
    }
    
    // Redirect to remove the query parameter but keep the filter
    $redirectUrl = "notifications.php" . ($filter !== 'all' ? "?filter=$filter" : "");
    header("Location: $redirectUrl");
    exit;
}

// Mark a single notification as read
if (isset($_GET['markRead']) && is_numeric($_GET['markRead'])) {
    $notificationID = (int)$_GET['markRead'];
    $updateQuery = "UPDATE ChangeLog SET Status = 'Read' WHERE LogID = ? AND MemberID = ?";
    $updateStmt = $conn->prepare($updateQuery);
    $updateStmt->bind_param("is", $notificationID, $memberID);
    if ($updateStmt->execute()) {
        $_SESSION['success_message'] = "Notification marked as read";
    } else {
        $_SESSION['error_message'] = "Failed to update notification";
    }
    
    // Redirect to remove the query parameter but keep the filter
    $redirectUrl = "notifications.php" . ($filter !== 'all' ? "?filter=$filter" : "");
    header("Location: $redirectUrl");
    exit;
}

// Count notifications by status
$statusCountQuery = "SELECT 
                     SUM(CASE WHEN Status = 'Not Read' THEN 1 ELSE 0 END) as unread,
                     SUM(CASE WHEN Status = 'Read' THEN 1 ELSE 0 END) as read_count,
                     SUM(CASE WHEN Status = 'Older' THEN 1 ELSE 0 END) as older
                     FROM ChangeLog 
                     WHERE MemberID = ?";
$statusCountStmt = $conn->prepare($statusCountQuery);
$statusCountStmt->bind_param("s", $memberID);
$statusCountStmt->execute();
$statusCountResult = $statusCountStmt->get_result();
$statusCounts = $statusCountResult->fetch_assoc();
$unreadCount = $statusCounts['unread'];
$readCount = $statusCounts['read_count'];
$olderCount = $statusCounts['older'];
$totalCount = $unreadCount + $readCount + $olderCount;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications</title>
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
        
        .home-container {
           min-height: 100vh;
           background: #f5f7fa;
           padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            margin-top: 30px;
        }
        
        .page-title {
            font-size: 1.8rem;
            color: #1e3c72;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .notifications-container {
            background-color: white;
            border-radius: 12px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .notification-item {
            display: flex;
            flex-direction: column;
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            position: relative;
            transition: background-color 0.2s;
        }
        
        .notification-item:hover {
            background-color: #f5f7fa;
        }
        
        .notification-unread {
            background-color: #f0f7ff;
        }
        
        .notification-read {
            background-color: white;
        }
        
        .notification-older {
            background-color: #f9f9f9;
            opacity: 0.8;
        }
        
        .notification-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }
        
        .notification-title {
            font-weight: bold;
            color: #1e3c72;
            display: flex;
            align-items: center;
        }
        
        .unread-indicator {
            display: inline-block;
            width: 10px;
            height: 10px;
            background-color: #e53e3e;
            border-radius: 50%;
            margin-right: 0.5rem;
        }
        
        .notification-date {
            color: #777;
            font-size: 0.9rem;
        }
        
        .notification-content {
            color: #333;
            margin-bottom: 0.5rem;
        }
        
        .notification-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 0.5rem;
        }
        
        .action-button {
            background: none;
            border: none;
            color: #1e3c72;
            cursor: pointer;
            font-size: 0.9rem;
            padding: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            text-decoration: none;
        }
        
        .action-button:hover {
            text-decoration: underline;
        }
        
        .mark-all-button {
            background-color: #1e3c72;
            color: white;
            border: none;
            border-radius: 6px;
            padding: 0.7rem 1.2rem;
            font-weight: 500;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            transition: background-color 0.2s;
        }
        
        .mark-all-button:hover {
            background-color: #1e3a8a;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
            gap: 0.5rem;
        }
        
        .pagination a, .pagination span {
            display: inline-block;
            padding: 0.5rem 1rem;
            border-radius: 4px;
            text-decoration: none;
            color: #1e3c72;
            background-color: white;
            transition: background-color 0.2s;
        }
        
        .pagination a:hover {
            background-color: #f0f7ff;
        }
        
        .pagination .active {
            background-color: #1e3c72;
            color: white;
        }
        
        .no-notifications {
            padding: 3rem;
            text-align: center;
            color: #777;
        }
        
        .alert {
            padding: 1rem;
            margin-bottom: 1.5rem;
            border-radius: 6px;
            opacity: 1;
            transition: opacity 0.5s ease;
        }
        
        .alert-success {
            background-color: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        
        .alert-danger {
            background-color: #fee2e2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        
        .alert-info {
            background-color: #dbeafe;
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }
        
        .fade-out {
            opacity: 0;
        }
        
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .filter-dropdown select {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            border: 1px solid #ddd;
            background-color: white;
            color: #1e3c72;
            font-size: 0.9rem;
            cursor: pointer;
            outline: none;
        }

        .filter-dropdown select:focus {
            border-color: #1e3c72;
        }
    </style>
</head>
<body>
    <div class="home-container">
        <?php include '../templates/navbar-member.php'; ?>
    <div class="container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-bell"></i> Notifications
                <?php if ($unreadCount > 0): ?>
                <span class="notification-badge"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </h1>
            
            <div class="header-actions">
                <div class="filter-dropdown">
                    <select id="notificationFilter" onchange="applyFilter(this.value)">
                        <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Notifications (<?php echo $totalCount; ?>)</option>
                        <option value="unread" <?php echo $filter === 'unread' ? 'selected' : ''; ?>>Unread (<?php echo $unreadCount; ?>)</option>
                        <option value="read" <?php echo $filter === 'read_count' ? 'selected' : ''; ?>>Read (<?php echo $readCount; ?>)</option>
                        <option value="older" <?php echo $filter === 'older' ? 'selected' : ''; ?>>Older (<?php echo $olderCount; ?>)</option>
                    </select>
                </div>
                
                <?php if ($unreadCount > 0): ?>
                <a href="notifications.php?markAllRead=1<?php echo $filter !== 'all' ? '&filter='.$filter : ''; ?>" class="mark-all-button">
                    <i class="fas fa-check-double"></i> Mark All as Read
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <?php 
            echo htmlspecialchars($_SESSION['success_message']); 
            unset($_SESSION['success_message']); // Clear the message after displaying
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
        
        <div class="notifications-container">
            <?php if (empty($notifications)): ?>
            <div class="no-notifications">
                <i class="fas fa-inbox fa-3x" style="color: #ddd; margin-bottom: 1rem;"></i>
                <p>You don't have any notifications<?php echo $filter !== 'all' ? ' in this category' : '' ?>.</p>
            </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                <div class="notification-item <?php echo $notification['Status'] === 'Not Read' ? 'notification-unread' : 
                         ($notification['Status'] === 'Older' ? 'notification-older' : 'notification-read'); ?>">
                    <div class="notification-header">
                        <div class="notification-title">
                            <?php if ($notification['Status'] === 'Not Read'): ?>
                            <span class="unread-indicator"></span>
                            <?php elseif ($notification['Status'] === 'Older'): ?>
                            <span class="older-indicator" title="Notification older than 30 days"><i class="fas fa-clock" style="font-size: 0.8rem; color: #aaa; margin-right: 0.5rem;"></i></span>
                            <?php endif; ?>
                            <?php echo htmlspecialchars($notification['RecordType']); ?> Update
                        </div>
                        <div class="notification-date"><?php echo htmlspecialchars($notification['FormattedDate']); ?></div>
                    </div>
                    <div class="notification-content">
                        <?php echo htmlspecialchars($notification['ChangeDetails']); ?>
                    </div>
                    <div class="notification-actions">
                        <?php if ($notification['Status'] === 'Not Read' || $notification['Status'] === 'Older'): ?>
                        <a href="notifications.php?markRead=<?php echo $notification['LogID']; ?><?php echo $filter !== 'all' ? '&filter='.$filter : ''; ?>" class="action-button">
                            <i class="fas fa-check"></i> Mark as Read
                        </a>
                        <?php else: ?>
                        <span class="action-button" style="color: #888; cursor: default;">
                            <i class="fas fa-check"></i> Read
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="notifications.php?page=<?php echo $page - 1; ?><?php echo $filter !== 'all' ? '&filter='.$filter : ''; ?>">
                <i class="fas fa-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <?php if ($i == $page): ?>
            <span class="active"><?php echo $i; ?></span>
            <?php else: ?>
            <a href="notifications.php?page=<?php echo $i; ?><?php echo $filter !== 'all' ? '&filter='.$filter : ''; ?>">
                <?php echo $i; ?>
            </a>
            <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="notifications.php?page=<?php echo $page + 1; ?><?php echo $filter !== 'all' ? '&filter='.$filter : ''; ?>">
                <i class="fas fa-chevron-right"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    </div>
    
    <?php include '../templates/footer.php'; ?>

    <script>
        function applyFilter(filter) {
            window.location.href = 'notifications.php?filter=' + filter;
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize alerts EXCEPT notification alerts
            const alertElements = document.querySelectorAll('.alert');
            alertElements.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.transition = 'opacity 0.5s ease';
                    alert.style.opacity = '0';
                    
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 4000);
            });
        });
    </script>
</body>
</html>