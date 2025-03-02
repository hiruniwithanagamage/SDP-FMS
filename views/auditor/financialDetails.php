<?php
    session_start();
    require_once "../../config/database.php";

    // Validate and sanitize year input
    $currentTerm = "2025"; // Default fallback
    try {
        // Get current term from database using prepared statement
        $stmt = prepare("SELECT year FROM Static ORDER BY year DESC LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $currentTerm = $row['year'];
            }
            $stmt->close();
        }

        // Validate and sanitize year input
        $selectedYear = filter_input(INPUT_GET, 'year', FILTER_VALIDATE_INT) ?: $currentTerm;
    } catch (Exception $e) {
        error_log("Error fetching current term: " . $e->getMessage());
        $selectedYear = $currentTerm;
    }

    // Function to fetch comprehensive financial details using prepared statements
    function fetchFinancialDetails($term) {
        $details = [
            'membership_fees' => [
                'total_amount' => 0,
                'paid_count' => 0,
                'unpaid_count' => 0
            ],
            'loans' => [
                'total_amount' => 0,
                'pending_count' => 0,
                'approved_count' => 0,
                'rejected_count' => 0
            ],
            'death_welfare' => [
                'total_amount' => 0,
                'pending_count' => 0,
                'approved_count' => 0,
                'rejected_count' => 0
            ],
            'fines' => [
                'total_amount' => 0,
                'paid_count' => 0,
                'unpaid_count' => 0,
                'late_fines' => 0,
                'absent_fines' => 0,
                'violation_fines' => 0
            ],
            'expenses' => [
                'total_amount' => 0,
                'categories' => []
            ]
        ];

        try {
            $conn = getConnection();

            // Membership Fees
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(SUM(Amount), 0) as total_amount,
                    COALESCE(SUM(CASE WHEN IsPaid = 'Yes' THEN 1 ELSE 0 END), 0) as paid_count,
                    COALESCE(SUM(CASE WHEN IsPaid = 'No' THEN 1 ELSE 0 END), 0) as unpaid_count
                FROM MembershipFee 
                WHERE Term = ?
            ");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $details['membership_fees'] = [
                    'total_amount' => $row['total_amount'],
                    'paid_count' => $row['paid_count'],
                    'unpaid_count' => $row['unpaid_count']
                ];
            }
            $stmt->close();

            // Loans
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(SUM(Amount), 0) as total_amount,
                    COALESCE(SUM(CASE WHEN Status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count,
                    COALESCE(SUM(CASE WHEN Status = 'approved' THEN 1 ELSE 0 END), 0) as approved_count,
                    COALESCE(SUM(CASE WHEN Status = 'rejected' THEN 1 ELSE 0 END), 0) as rejected_count
                FROM Loan 
                WHERE Term = ?
            ");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $details['loans'] = [
                    'total_amount' => $row['total_amount'],
                    'pending_count' => $row['pending_count'],
                    'approved_count' => $row['approved_count'],
                    'rejected_count' => $row['rejected_count']
                ];
            }
            $stmt->close();

            // Death Welfare
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(SUM(Amount), 0) as total_amount,
                    COALESCE(SUM(CASE WHEN Status = 'pending' THEN 1 ELSE 0 END), 0) as pending_count,
                    COALESCE(SUM(CASE WHEN Status = 'approved' THEN 1 ELSE 0 END), 0) as approved_count,
                    COALESCE(SUM(CASE WHEN Status = 'rejected' THEN 1 ELSE 0 END), 0) as rejected_count
                FROM DeathWelfare 
                WHERE Term = ?
            ");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $details['death_welfare'] = [
                    'total_amount' => $row['total_amount'],
                    'pending_count' => $row['pending_count'],
                    'approved_count' => $row['approved_count'],
                    'rejected_count' => $row['rejected_count']
                ];
            }
            $stmt->close();

            // Fines
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(SUM(Amount), 0) as total_amount,
                    COALESCE(SUM(CASE WHEN IsPaid = 'Yes' THEN 1 ELSE 0 END), 0) as paid_count,
                    COALESCE(SUM(CASE WHEN IsPaid = 'No' THEN 1 ELSE 0 END), 0) as unpaid_count,
                    COALESCE(SUM(CASE WHEN Description = 'late' THEN Amount ELSE 0 END), 0) as late_fines,
                    COALESCE(SUM(CASE WHEN Description = 'absent' THEN Amount ELSE 0 END), 0) as absent_fines,
                    COALESCE(SUM(CASE WHEN Description = 'violation' THEN Amount ELSE 0 END), 0) as violation_fines
                FROM Fine 
                WHERE Term = ?
            ");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $details['fines'] = [
                    'total_amount' => $row['total_amount'],
                    'paid_count' => $row['paid_count'],
                    'unpaid_count' => $row['unpaid_count'],
                    'late_fines' => $row['late_fines'],
                    'absent_fines' => $row['absent_fines'],
                    'violation_fines' => $row['violation_fines']
                ];
            }
            $stmt->close();

            // Expenses
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(SUM(Amount), 0) as total_amount,
                    Category,
                    COALESCE(SUM(Amount), 0) as category_total
                FROM Expenses 
                WHERE Term = ?
                GROUP BY Category
            ");
            $stmt->bind_param("i", $term);
            $stmt->execute();
            $result = $stmt->get_result();
            $details['expenses']['total_amount'] = 0;
            $details['expenses']['categories'] = [];
            while ($row = $result->fetch_assoc()) {
                $details['expenses']['total_amount'] += $row['total_amount'];
                $details['expenses']['categories'][] = [
                    'name' => $row['Category'],
                    'amount' => $row['category_total']
                ];
            }
            $stmt->close();

        } catch (Exception $e) {
            // Log error or handle as needed
            error_log("Error fetching financial details: " . $e->getMessage());
        }

        return $details;
    }

    // Fetch financial details for the selected year
    $financialDetails = fetchFinancialDetails($selectedYear);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Financial Details - Term <?php echo htmlspecialchars($selectedYear); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        .home-container {
           min-height: 100vh;
           background: #f5f7fa;
           padding: 2rem;
       }

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f6f9;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            /* background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1); */
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .term-select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .financial-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
        }

        .financial-card {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .financial-card h3 {
            margin-bottom: 15px;
            color: #1e3c72;
        }

        .financial-card .amount {
            font-size: 1.5em;
            font-weight: bold;
            color: #1e3c72;
        }

        .financial-details {
            margin-top: 15px;
            font-size: 0.9em;
            color: #666;
        }

        .expenses-breakdown {
            margin-top: 20px;
        }

        .expenses-category {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }

        .section-title {
            margin-top: 30px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1e3c72;
            color: #1e3c72;
        }
    </style>
</head>
<body>
<div class="home-container">
<?php include '../templates/navbar-admin.php'; ?>
    <div class="container">
        <div class="header">
            <h1>Financial Details - Term <?php echo htmlspecialchars($selectedYear); ?></h1>
            <select class="term-select" onchange="updateTerm(this.value)">
                <?php 
                $startYear = max(2020, $currentTerm - 2); // Prevent negative or unreasonable years
                for($y = $currentTerm; $y >= $startYear; $y--): 
                ?>
                    <option value="<?php echo $y; ?>" <?php echo $y == $selectedYear ? 'selected' : ''; ?>>
                        Term <?php echo $y; ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>

        <div class="financial-grid">
            <!-- Membership Fees Card -->
            <div class="financial-card">
                <h3>Membership Fees</h3>
                <div class="amount">Rs. <?php echo number_format($financialDetails['membership_fees']['total_amount'], 2); ?></div>
                <div class="financial-details">
                    Paid: <?php echo $financialDetails['membership_fees']['paid_count']; ?> 
                    | Unpaid: <?php echo $financialDetails['membership_fees']['unpaid_count']; ?>
                </div>
            </div>

            <!-- Loans Card -->
            <div class="financial-card">
                <h3>Loans</h3>
                <div class="amount">Rs. <?php echo number_format($financialDetails['loans']['total_amount'], 2); ?></div>
                <div class="financial-details">
                    Pending: <?php echo $financialDetails['loans']['pending_count']; ?> 
                    | Approved: <?php echo $financialDetails['loans']['approved_count']; ?> 
                    | Rejected: <?php echo $financialDetails['loans']['rejected_count']; ?>
                </div>
            </div>

            <!-- Death Welfare Card -->
            <div class="financial-card">
                <h3>Death Welfare</h3>
                <div class="amount">Rs. <?php echo number_format($financialDetails['death_welfare']['total_amount'], 2); ?></div>
                <div class="financial-details">
                    Pending: <?php echo $financialDetails['death_welfare']['pending_count']; ?> 
                    | Approved: <?php echo $financialDetails['death_welfare']['approved_count']; ?> 
                    | Rejected: <?php echo $financialDetails['death_welfare']['rejected_count']; ?>
                </div>
            </div>

            <!-- Fines Card -->
            <div class="financial-card">
                <h3>Fines</h3>
                <div class="amount">Rs. <?php echo number_format($financialDetails['fines']['total_amount'], 2); ?></div>
                <div class="financial-details">
                    Paid: <?php echo $financialDetails['fines']['paid_count']; ?> 
                    | Unpaid: <?php echo $financialDetails['fines']['unpaid_count']; ?>
                </div>
            </div>
        </div>

        <h2 class="section-title">Fines Breakdown</h2>
        <div class="financial-grid">
            <div class="financial-card">
                <h3>Late Fines</h3>
                <div class="amount">Rs. <?php echo number_format($financialDetails['fines']['late_fines'], 2); ?></div>
            </div>
            <div class="financial-card">
                <h3>Absent Fines</h3>
                <div class="amount">Rs. <?php echo number_format($financialDetails['fines']['absent_fines'], 2); ?></div>
            </div>
            <div class="financial-card">
                <h3>Violation Fines</h3>
                <div class="amount">Rs. <?php echo number_format($financialDetails['fines']['violation_fines'], 2); ?></div>
            </div>
        </div>

        <h2 class="section-title">Expenses Breakdown</h2>
        <div class="financial-card expenses-breakdown">
            <h3>Total Expenses: Rs. <?php echo number_format($financialDetails['expenses']['total_amount'], 2); ?></h3>
            <?php foreach($financialDetails['expenses']['categories'] as $category): ?>
                <div class="expenses-category">
                    <span><?php echo htmlspecialchars($category['name']); ?></span>
                    <span>Rs. <?php echo number_format($category['amount'], 2); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
    
    <script>
    function updateTerm(year) {
        // Use built-in PHP URL encoding to prevent XSS
        window.location.href = 'financialDetails.php?year=' + encodeURIComponent(year);
    }
    </script>
</body>
</html>