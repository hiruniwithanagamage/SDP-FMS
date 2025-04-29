<?php
session_start();
require_once "../../config/database.php";

// Check if user is logged in and has treasurer role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'treasurer') {
    header("Location: ../login.php");
    exit();
}

// Get the current active term
function getCurrentTerm() {
    $sql = "SELECT year FROM Static WHERE status = 'active'";
    $result = search($sql);
    $row = $result->fetch_assoc();
    return $row['year'] ?? date('Y');
}

$currentTerm = getCurrentTerm();

// You can add any other data you want to show alongside the Power BI dashboard
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Analytics Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .dashboard-container {
            min-height: 100vh;
            background: #f5f7fa;
            padding: 2rem;
        }

        .content {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 35px 0 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .dashboard-wrapper {
            background: white;
            border-radius: 15px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
        }

        .dashboard-tabs {
            display: flex;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }

        .dashboard-tab {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 600;
            color: #666;
            position: relative;
        }

        .dashboard-tab.active {
            color: #1e3c72;
        }

        .dashboard-tab.active::after {
            content: '';
            position: absolute;
            bottom: -1px;
            left: 0;
            width: 100%;
            height: 3px;
            background: #1e3c72;
        }

        .powerbi-container {
            width: 100%;
            height: 700px;
            border: none;
            overflow: hidden;
        }

        .dashboard-instructions {
            background: #f0f5ff;
            border-left: 4px solid #1e3c72;
            padding: 15px;
            margin: 20px 0;
            border-radius: 0 10px 10px 0;
            font-size: 0.9rem;
        }

        .dashboard-instructions h3 {
            color: #1e3c72;
            margin-bottom: 10px;
        }

        .dashboard-instructions p {
            margin-bottom: 10px;
        }

        .dashboard-instructions ul {
            margin-left: 20px;
            padding-bottom: 10px;
        }

        .term-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.2);
            padding: 8px 15px;
            border-radius: 50px;
            backdrop-filter: blur(5px);
        }

        .term-selector select {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            padding: 5px 10px;
            border-radius: 5px;
            color: white;
            outline: none;
        }

        .term-selector select option {
            background: #2a5298;
            color: white;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                text-align: center;
                gap: 1rem;
            }
            
            .term-selector {
                width: 100%;
                justify-content: center;
            }
            
            .powerbi-container {
                height: 500px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        <div class="content">
            <div class="page-header">
                <h1>Financial Analytics Dashboard</h1>
                <div class="term-selector">
                    <span>Term:</span>
                    <select id="term-select" onchange="changeTerm()">
                        <?php
                        // Generate options for the last 5 years
                        $currentYear = (int)date('Y');
                        for ($i = 0; $i < 5; $i++) {
                            $year = $currentYear - $i;
                            $selected = ($year == $currentTerm) ? 'selected' : '';
                            echo "<option value=\"$year\" $selected>$year</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="dashboard-wrapper">
                <div class="dashboard-tabs">
                    <div class="dashboard-tab active" onclick="changeTab('financial-summary')">Financial Summary</div>
                    <div class="dashboard-tab" onclick="changeTab('member-analysis')">Member Analysis</div>
                    <div class="dashboard-tab" onclick="changeTab('loan-performance')">Loan Performance</div>
                </div>

                <div class="dashboard-instructions">
                    <h3>Dashboard Information</h3>
                    <p>This interactive dashboard provides comprehensive financial analytics for the Friendly Society:</p>
                    <ul>
                        <li>View overall financial health and key metrics</li>
                        <li>Analyze member payment patterns and outstanding dues</li>
                        <li>Track loan performance and interest collection</li>
                    </ul>
                    <p>Use the tabs above to navigate between different analytics views.</p>
                </div>

                <!-- Power BI Embed Container -->
                <div id="powerbi-embed-container">
                    <!-- Replace the src attribute with your actual Power BI report embed URL -->
                    <iframe 
                        class="powerbi-container" 
                        id="powerbi-frame"
                        src="https://app.powerbi.com/reportEmbed?reportId=YOUR_REPORT_ID&autoAuth=true&pageName=financial-summary" 
                        allowFullScreen="true">
                    </iframe>
                </div>
            </div>
        </div>
        <?php include '../templates/footer.php'; ?>
    </div>

    <script>
        // Function to change the active tab
        function changeTab(tabName) {
            // Update active tab styling
            const tabs = document.querySelectorAll('.dashboard-tab');
            tabs.forEach(tab => tab.classList.remove('active'));
            event.target.classList.add('active');
            
            // Update the Power BI iframe to show the selected page
            const powerbiFrame = document.getElementById('powerbi-frame');
            const currentSrc = powerbiFrame.src;
            const baseUrl = currentSrc.split('&pageName=')[0];
            powerbiFrame.src = baseUrl + '&pageName=' + tabName;
        }

        // Function to change the term/year
        function changeTerm() {
            const selectedTerm = document.getElementById('term-select').value;
            // You can update the iframe URL with a filter for the selected year
            // For example: add a filter parameter to the URL
            const powerbiFrame = document.getElementById('powerbi-frame');
            const currentSrc = powerbiFrame.src;
            
            // This depends on how your Power BI report is set up
            // You might need to adjust this based on your specific report configuration
            if (currentSrc.includes('&filter=')) {
                // If filter already exists, update it
                powerbiFrame.src = currentSrc.replace(/&filter=Year%20eq%20\d+/, `&filter=Year%20eq%20${selectedTerm}`);
            } else {
                // If no filter exists, add it
                powerbiFrame.src = currentSrc + `&filter=Year%20eq%20${selectedTerm}`;
            }
        }
    </script>
</body>
</html>