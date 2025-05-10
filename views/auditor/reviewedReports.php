<?php
    session_start();
    require_once "../../config/database.php";

    // Check if user is logged in and is an auditor
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'auditor') {
        header("Location: ../../login.php");
        exit();
    }

    // Function to fetch current term securely if not provided in URL
    function getCurrentTerm() {
        try {
            $stmt = prepare("SELECT year FROM Static WHERE status = 'active'");
            
            if (!$stmt) {
                error_log("Prepare failed");
                return date('Y'); // Fallback to current year
            }
            
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $stmt->close();
                return $row['year'];
            }
            
            $stmt->close();
            return date('Y'); // Fallback to current year
        } catch (Exception $e) {
            error_log("Error fetching current term: " . $e->getMessage());
            return date('Y');
        }
    }

    // Get term from URL parameter or default to current term
    $currentTerm = isset($_GET['term']) ? intval($_GET['term']) : getCurrentTerm();

    // Fetch reviewed reports for the specified term
    function fetchReviewedReports($term) {
        $reports = [];
        
        try {
            // Query to get all reviewed reports for the specified term with treasurer info
            $sql = "SELECT frv.ReportID, frv.VersionID, frv.Date, frv.Total_Income, 
                    frv.Total_Expenses, frv.Net_Income, frv.Comments, 
                    t.Name as TreasurerName, frv.Treasurer_TreasurerID
                    FROM FinancialReportVersions frv
                    JOIN Treasurer t ON frv.Treasurer_TreasurerID = t.TreasurerID
                    WHERE frv.Status = 'reviewed' AND frv.Term = ?
                    ORDER BY frv.Date DESC";
            
            $stmt = prepare($sql);
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    // Format currency values
                    $row['Total_Income_Formatted'] = number_format($row['Total_Income'], 2);
                    $row['Total_Expenses_Formatted'] = number_format($row['Total_Expenses'], 2);
                    $row['Net_Income_Formatted'] = number_format($row['Net_Income'], 2);
                    
                    // Format date
                    $reportDate = new DateTime($row['Date']);
                    $row['Date_Formatted'] = $reportDate->format('F j, Y');
                    
                    $reports[] = $row;
                }
            }
            
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error fetching reviewed reports: " . $e->getMessage());
        }
        
        return $reports;
    }

    // Get all available terms for dropdown
    function getAllTerms() {
        $terms = [];
        
        try {
            $sql = "SELECT DISTINCT Term FROM FinancialReportVersions ORDER BY Term DESC";
            $result = search($sql);
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $terms[] = $row['Term'];
                }
            }
        } catch (Exception $e) {
            error_log("Error fetching terms: " . $e->getMessage());
        }
        
        return $terms;
    }

    // Function to get report comments
    function getReportComments($reportId, $versionId) {
        $comments = [];
        
        try {
            $sql = "SELECT Comment, CommentDate FROM ReportComments 
                    WHERE ReportID = ? AND VersionID = ?
                    ORDER BY CommentDate DESC";
            
            $stmt = prepare($sql);
            $stmt->bind_param("ss", $reportId, $versionId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $commentDate = new DateTime($row['CommentDate']);
                    $row['CommentDate_Formatted'] = $commentDate->format('M j, Y g:i A');
                    $comments[] = $row;
                }
            }
            
            $stmt->close();
        } catch (Exception $e) {
            error_log("Error fetching report comments: " . $e->getMessage());
        }
        
        return $comments;
    }

    // Fetch reviewed reports for the selected term
    $reviewedReports = fetchReviewedReports($currentTerm);
    
    // Get all available terms for the dropdown
    $availableTerms = getAllTerms();

    // Handle approving a report
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approve'])) {
        $reportId = $_POST['report_id'];
        $versionId = $_POST['version_id'];
        $auditorId = $_SESSION['auditor_id'];
        
        try {
            // Start transaction
            beginTransaction();
            
            // Update report status to approved
            $sql = "UPDATE FinancialReportVersions 
                    SET Status = 'approved' 
                    WHERE ReportID = ? AND VersionID = ?";
            
            $stmt = prepare($sql);
            $stmt->bind_param("ss", $reportId, $versionId);
            $stmt->execute();
            
            // Add a comment about approval
            $comment = "Report approved by auditor.";
            $sql = "INSERT INTO ReportComments (ReportID, VersionID, Comment) 
                    VALUES (?, ?, ?)";
            
            $stmt = prepare($sql);
            $stmt->bind_param("sss", $reportId, $versionId, $comment);
            $stmt->execute();
            
            // Commit transaction
            commit();
            
            // Redirect to refresh the page
            header("Location: reviewedReports.php?term=" . $currentTerm . "&success=1");
            exit();
            
        } catch (Exception $e) {
            // Rollback transaction on error
            rollback();
            error_log("Error approving report: " . $e->getMessage());
            header("Location: reviewedReports.php?term=" . $currentTerm . "&error=1");
            exit();
        }
    }
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviewed Reports - Auditor Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #1e3c72;
            --primary-light: #4e70aa;
            --secondary-color: #2a5298;
            --accent-color: #4caf50;
            --warning-color: #ff9800;
            --danger-color: #f44336;
            --light-color: #f5f7fa;
            --dark-color: #333;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --hover-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            --border-radius: 12px;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--light-color);
            color: var(--dark-color);
            line-height: 1.6;
        }

        .home-container {
           min-height: 100vh;
           padding: 2rem;
           display: flex;
           flex-direction: column;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            color: white;
            padding: 2rem;
            border-radius: var(--border-radius);
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .header h1 {
            font-size: 1.8rem;
            margin: 0;
        }

        .term-selector {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .term-selector select {
            padding: 0.5rem 1rem;
            border-radius: 50px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.2);
            color: white;
            outline: none;
            font-weight: 600;
        }
        
        .term-selector select option {
            background: var(--primary-color);
            color: white;
        }

        .back-button {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .back-button:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .info-panel {
            background: white;
            border-radius: var(--border-radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
        }

        .info-panel h2 {
            color: var(--primary-color);
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .info-panel p {
            margin-bottom: 0.5rem;
        }

        .reports-container {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .report-card {
            background: white;
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .report-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--hover-shadow);
        }

        .report-header {
            background: var(--primary-light);
            color: white;
            padding: 1rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .report-header h3 {
            font-size: 1.2rem;
            margin: 0;
        }

        .report-date {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .report-body {
            padding: 1.5rem;
        }

        .report-row {
            display: flex;
            justify-content: space-between;
            padding: 0.7rem 0;
            border-bottom: 1px solid #eee;
        }

        .report-row:last-child {
            border-bottom: none;
        }

        .report-label {
            font-weight: 600;
            color: var(--primary-color);
        }

        .report-value {
            text-align: right;
        }

        .report-comments {
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px dashed #ddd;
        }

        .report-comment {
            font-style: italic;
            color: #666;
            margin-top: 0.5rem;
            font-size: 0.9rem;
        }

        .report-footer {
            padding: 1rem 1.5rem;
            background: #f9f9f9;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-top: 1px solid #eee;
        }

        .report-footer .treasurer-info {
            font-size: 0.9rem;
            color: #666;
        }

        .action-button {
            background: var(--accent-color);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s ease;
        }

        .action-button:hover {
            background: #3d9140;
        }

        .view-button {
            background: var(--primary-color);
            color: white;
            text-decoration: none;
            padding: 0.5rem 1.5rem;
            border-radius: 50px;
            font-weight: 600;
            transition: background 0.3s ease;
            display: inline-block;
        }

        .view-button:hover {
            background: var(--primary-light);
        }

        .empty-state {
            background: white;
            border-radius: var(--border-radius);
            padding: 3rem;
            text-align: center;
            box-shadow: var(--shadow);
        }

        .empty-state i {
            font-size: 4rem;
            color: #ddd;
            margin-bottom: 1.5rem;
        }

        .empty-state h3 {
            color: var(--primary-color);
            margin-bottom: 1rem;
        }

        .empty-state p {
            color: #777;
            max-width: 500px;
            margin: 0 auto;
        }

        .success-message {
            background: rgba(76, 175, 80, 0.1);
            color: var(--accent-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .error-message {
            background: rgba(244, 67, 54, 0.1);
            color: var(--danger-color);
            padding: 1rem;
            border-radius: var(--border-radius);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }

            .term-selector {
                flex-direction: column;
                width: 100%;
            }

            .term-selector select {
                width: 100%;
            }

            .back-button {
                width: 100%;
                justify-content: center;
            }

            .reports-container {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="home-container">
    <?php include '../templates/navbar-auditor.php'; ?>
    <div class="container">
        <div class="header">
            <h1>Reviewed Reports</h1>
            <div class="term-selector">
                <form action="" method="GET" id="term-form">
                    <select name="term" id="term-select" onchange="document.getElementById('term-form').submit();">
                        <?php foreach ($availableTerms as $term): ?>
                            <option value="<?php echo htmlspecialchars($term); ?>" <?php echo $term == $currentTerm ? 'selected' : ''; ?>>
                                Term <?php echo htmlspecialchars($term); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="home-auditor.php" class="back-button">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="success-message">
                <i class="fas fa-check-circle"></i>
                <span>Report has been successfully approved.</span>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="error-message">
                <i class="fas fa-exclamation-circle"></i>
                <span>An error occurred while approving the report. Please try again.</span>
            </div>
        <?php endif; ?>

        <div class="info-panel">
            <h2>Term <?php echo htmlspecialchars($currentTerm); ?> - Reviewed Reports</h2>
            <p>
                These reports are previous versions of financial statements. 
                They are available for reference and historical comparison purposes only. 
                These reports have already been processed and cannot be submitted or modified again.
            </p>
        </div>

        <?php if (empty($reviewedReports)): ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-check"></i>
                <h3>No Reviewed Reports</h3>
                <p>There are no reports in the 'reviewed' status for Term <?php echo htmlspecialchars($currentTerm); ?>. 
                   All reports have either been approved or are still pending review.</p>
            </div>
        <?php else: ?>
            <div class="reports-container">
                <?php foreach ($reviewedReports as $report): ?>
                    <div class="report-card">
                        <div class="report-header">
                            <h3>Report #V<?php echo htmlspecialchars(substr($report['VersionID'], -3)); ?></h3>
                            <span class="report-date"><?php echo htmlspecialchars($report['Date_Formatted']); ?></span>
                        </div>
                        <div class="report-body">
                            <div class="report-row">
                                <span class="report-label">Total Income</span>
                                <span class="report-value">Rs. <?php echo htmlspecialchars($report['Total_Income_Formatted']); ?></span>
                            </div>
                            <div class="report-row">
                                <span class="report-label">Total Expenses</span>
                                <span class="report-value">Rs. <?php echo htmlspecialchars($report['Total_Expenses_Formatted']); ?></span>
                            </div>
                            <div class="report-row">
                                <span class="report-label">Net Income</span>
                                <span class="report-value" style="font-weight: bold; color: <?php echo $report['Net_Income'] >= 0 ? 'green' : 'red'; ?>">
                                    Rs. <?php echo htmlspecialchars($report['Net_Income_Formatted']); ?>
                                </span>
                            </div>
                            
                            <?php if (!empty($report['Comments'])): ?>
                                <div class="report-comments">
                                    <div class="report-label">Comments</div>
                                    <div class="report-comment"><?php echo htmlspecialchars($report['Comments']); ?></div>
                                </div>
                            <?php endif; ?>
                            
                            <?php 
                            // Fetch and display comments for this report
                            $comments = getReportComments($report['ReportID'], $report['VersionID']);
                            if (!empty($comments)): 
                            ?>
                                <div class="report-comments">
                                    <div class="report-label">Audit Notes</div>
                                    <?php foreach ($comments as $comment): ?>
                                        <div class="report-comment">
                                            <?php echo htmlspecialchars($comment['Comment']); ?>
                                            <small style="display: block; color: #999; margin-top: 2px;">
                                                <?php echo htmlspecialchars($comment['CommentDate_Formatted']); ?>
                                            </small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="report-footer">
                            <div class="treasurer-info">
                                Submitted by: <?php echo htmlspecialchars($report['TreasurerName']); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    </div>
</body>
</html>