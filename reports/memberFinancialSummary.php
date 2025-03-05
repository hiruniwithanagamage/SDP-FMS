<?php
session_start();
require_once "../config/database.php";

// Check if user is logged in and is a treasurer or admin
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'treasurer' && $_SESSION['role'] !== 'admin')) {
    header("Location: ../loginProcess.php");
    exit();
}

// Function to get all members
function getAllMembers() {
    $sql = "SELECT 
            m.MemberID, 
            m.Name, 
            m.NIC, 
            m.Status,
            m.Joined_Date,
            (SELECT COUNT(*) FROM Loan WHERE Member_MemberID = m.MemberID AND Remain_Loan > 0) AS has_active_loan,
            (SELECT COALESCE(SUM(Remain_Loan), 0) FROM Loan WHERE Member_MemberID = m.MemberID) AS loan_balance,
            (SELECT COUNT(*) FROM MembershipFee mf LEFT JOIN MembershipFeePayment mfp ON mf.FeeID = mfp.FeeID 
             WHERE mf.Member_MemberID = m.MemberID AND mf.Term = YEAR(CURDATE()) AND mfp.PaymentID IS NULL) AS unpaid_fees
            FROM Member m
            ORDER BY m.Name";
    
    return search($sql);
}

// Get current year
$currentYear = date('Y');

// Search functionality
$searchTerm = isset($_GET['search']) ? $_GET['search'] : '';
$members = [];

if (!empty($searchTerm)) {
    // Search for members matching the search term
    $sql = "SELECT 
            m.MemberID, 
            m.Name, 
            m.NIC, 
            m.Status,
            m.Joined_Date,
            (SELECT COUNT(*) FROM Loan WHERE Member_MemberID = m.MemberID AND Remain_Loan > 0) AS has_active_loan,
            (SELECT COALESCE(SUM(Remain_Loan), 0) FROM Loan WHERE Member_MemberID = m.MemberID) AS loan_balance,
            (SELECT COUNT(*) FROM MembershipFee mf LEFT JOIN MembershipFeePayment mfp ON mf.FeeID = mfp.FeeID 
             WHERE mf.Member_MemberID = m.MemberID AND mf.Term = YEAR(CURDATE()) AND mfp.PaymentID IS NULL) AS unpaid_fees
            FROM Member m
            WHERE m.MemberID LIKE ? OR m.Name LIKE ? OR m.NIC LIKE ?
            ORDER BY m.Name";
    
    $stmt = prepare($sql);
    $searchParam = "%$searchTerm%";
    $stmt->bind_param("sss", $searchParam, $searchParam, $searchParam);
    $stmt->execute();
    $members = $stmt->get_result();
} else {
    // Get all members
    $members = getAllMembers();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Financial Summary - Eksat Maranadhara Samithiya</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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

        .container {
            min-height: 100vh;
            background: #f5f7fa;
            padding: 2rem;
        }

        .content {
            max-width: 1200px;
            margin: 0 auto;
            margin-top: 30px;
        }

        .page-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .search-bar {
            margin-bottom: 2rem;
            display: flex;
        }

        .search-input {
            flex: 1;
            padding: 1rem;
            border: 1px solid #ced4da;
            border-radius: 5px 0 0 5px;
            font-size: 1rem;
        }

        .search-button {
            padding: 1rem 1.5rem;
            background: #1e3c72;
            color: white;
            border: none;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
            transition: background 0.3s ease;
        }

        .search-button:hover {
            background: #2a5298;
        }

        .card {
            background: white;
            border-radius: 10px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
        }

        .members-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .members-table th,
        .members-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .members-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #1e3c72;
        }

        .members-table tr:hover {
            background-color: #f8f9fa;
        }

        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 15px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .status-active {
            background-color: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }

        .btn {
            padding: 0.6rem 1rem;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
        }

        .btn i {
            margin-right: 0.5rem;
        }

        .btn-primary {
            background: #1e3c72;
            color: white;
        }

        .btn-primary:hover {
            background: #2a5298;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 2rem;
        }

        .pagination a {
            padding: 0.5rem 1rem;
            margin: 0 0.25rem;
            border-radius: 5px;
            text-decoration: none;
            color: #1e3c72;
            background-color: white;
            transition: background-color 0.3s ease;
        }

        .pagination a.active {
            background-color: #1e3c72;
            color: white;
        }

        .pagination a:hover:not(.active) {
            background-color: #e9ecef;
        }

        .no-results {
            text-align: center;
            padding: 2rem;
            color: #6c757d;
        }

        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                text-align: center;
                padding: 1.5rem;
            }

            .search-bar {
                flex-direction: column;
            }

            .search-input {
                border-radius: 5px;
                margin-bottom: 0.5rem;
            }

            .search-button {
                border-radius: 5px;
                width: 100%;
            }

            .members-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <?php include '../views/templates/navbar-treasurer.php'; ?>

        <div class="content">
            <div class="page-header">
                <h1>Member Financial Summary</h1>
                <div>
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <div class="card">
                <form action="memberFinancialSummary.php" method="GET" class="search-bar">
                    <input type="text" name="search" class="search-input" placeholder="Search by Member ID, Name or NIC" value="<?php echo htmlspecialchars($searchTerm); ?>">
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i> Search
                    </button>
                </form>

                <?php if ($members->num_rows > 0): ?>
                    <table class="members-table">
                        <thead>
                            <tr>
                                <th>Member ID</th>
                                <th>Name</th>
                                <th>NIC</th>
                                <th>Status</th>
                                <th>Joined Date</th>
                                <th>Loan Balance</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $members->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['MemberID']); ?></td>
                                    <td><?php echo htmlspecialchars($row['Name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NIC']); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $row['Status'] === 'active' ? 'status-active' : 'status-inactive'; ?>">
                                            <?php echo ucfirst($row['Status']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($row['Joined_Date'])); ?></td>
                                    <td>
                                        <?php if ($row['has_active_loan'] > 0): ?>
                                            Rs. <?php echo number_format($row['loan_balance'], 2); ?>
                                        <?php else: ?>
                                            No Active Loans
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="memberFSPdf.php?id=<?php echo $row['MemberID']; ?>" class="btn btn-primary">
                                            <i class="fas fa-file-invoice-dollar"></i> View Summary
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="no-results">
                        <i class="fas fa-search" style="font-size: 2rem; color: #6c757d; margin-bottom: 1rem;"></i>
                        <p>No members found matching your search criteria.</p>
                    </div>
                <?php endif; ?>

                <!-- Pagination - This can be implemented if needed -->
                <!-- <div class="pagination">
                    <a href="#">&laquo;</a>
                    <a href="#" class="active">1</a>
                    <a href="#">2</a>
                    <a href="#">3</a>
                    <a href="#">&raquo;</a>
                </div> -->
            </div>
        </div>
        
        <?php include '../views/templates/footer.php'; ?>
    </div>
</body>
</html>