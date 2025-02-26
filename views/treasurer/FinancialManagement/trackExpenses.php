<?php
session_start();
require_once "../../../config/database.php";

// Initialize search parameters
$category = isset($_GET['category']) ? $_GET['category'] : '';
$method = isset($_GET['method']) ? $_GET['method'] : '';
$fromDate = isset($_GET['from_date']) ? $_GET['from_date'] : '';
$toDate = isset($_GET['to_date']) ? $_GET['to_date'] : '';
$term = isset($_GET['term']) ? $_GET['term'] : '';

// Get all available terms
$termQuery = "SELECT DISTINCT Term FROM Expenses ORDER BY Term DESC";
$termResult = Database::search($termQuery);
$terms = [];
if ($termResult && $termResult->num_rows > 0) {
    while ($termRow = $termResult->fetch_assoc()) {
        $terms[] = $termRow['Term'];
    }
}

// Base query
$baseQuery = "SELECT e.*, t.Name as TreasurerName 
              FROM Expenses e 
              LEFT JOIN Treasurer t ON e.Treasurer_TreasurerID = t.TreasurerID
              WHERE 1=1";

// Apply filters if provided
if (!empty($category)) {
    $baseQuery .= " AND e.Category = '" . $category . "'";
}
if (!empty($method)) {
    $baseQuery .= " AND e.Method = '" . $method . "'";
}
if (!empty($fromDate)) {
    $baseQuery .= " AND e.Date >= '" . $fromDate . "'";
}
if (!empty($toDate)) {
    $baseQuery .= " AND e.Date <= '" . $toDate . "'";
}
if (!empty($term)) {
    $baseQuery .= " AND e.Term = " . $term;
}

// Add ordering
$baseQuery .= " ORDER BY e.Date DESC";

// Execute query
$result = Database::search($baseQuery);

// Calculate total expenses and category breakdown
$totalExpenses = 0;
$categoryTotals = array();

if ($result && $result->num_rows > 0) {
    // Create a copy of the result to iterate through for calculations
    $calcResult = Database::search($baseQuery);
    
    while ($row = $calcResult->fetch_assoc()) {
        $totalExpenses += $row['Amount'];
        
        // Track totals by category
        if (!isset($categoryTotals[$row['Category']])) {
            $categoryTotals[$row['Category']] = 0;
        }
        $categoryTotals[$row['Category']] += $row['Amount'];
    }
}

// Define delete functionality
if (isset($_GET['delete']) && isset($_GET['id'])) {
    $expenseId = $_GET['id'];
    
    // Check if this expense is linked to a Death Welfare
    $checkDeathWelfareQuery = "SELECT * FROM DeathWelfare WHERE Expense_ExpenseID = '$expenseId'";
    $checkResult = Database::search($checkDeathWelfareQuery);
    
    if ($checkResult && $checkResult->num_rows > 0) {
        $_SESSION['error_message'] = "Cannot delete this expense as it is linked to a Death Welfare record.";
    } else {
        // Delete the expense
        $deleteQuery = "DELETE FROM Expenses WHERE ExpenseID = '$expenseId'";
        
        try {
            Database::iud($deleteQuery);
            $_SESSION['success_message'] = "Expense deleted successfully";
            
            // Redirect to remove the 'delete' parameter from URL
            header("Location: trackExpenses.php");
            exit();
        } catch(Exception $e) {
            $_SESSION['error_message'] = "Error deleting expense: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Track Expenses</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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

        h1, h2 {
            color: #1a237e;
            margin-bottom: 1.5rem;
        }

        .filter-section {
            background-color: #f0f2f5;
            padding: 1.5rem;
            border-radius: 5px;
            margin-bottom: 2rem;
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: #333;
            font-weight: bold;
        }

        .form-group select, .form-group input {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
            transition: border-color 0.3s ease;
        }

        .form-group select:focus, .form-group input:focus {
            border-color: #1a237e;
            outline: none;
        }

        .btn {
            padding: 0.8rem 2rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-filter {
            background-color: #1a237e;
            color: white;
        }

        .btn-filter:hover {
            background-color: #0d1757;
        }

        .btn-reset {
            background-color: white;
            color: #1a237e;
            border: 2px solid #1a237e;
        }

        .btn-reset:hover {
            background-color: #f5f7fa;
        }

        .btn-add {
            background-color: #1a237e;
            color: white;
            text-decoration: none;
            float: right;
        }

        .btn-add:hover {
            background-color: #0d1757;
        }

        .btn-delete {
            background-color: #f44336;
            color: white;
            padding: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-delete:hover {
            background-color: #d32f2f;
        }

        .btn-view {
            background-color: #2196F3;
            color: white;
            padding: 0.5rem;
            font-size: 0.9rem;
        }

        .btn-view:hover {
            background-color: #1976D2;
        }

        .expense-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .expense-table th, .expense-table td {
            padding: 0.8rem;
            text-align: left;
            border-bottom: 1px solid #e0e0e0;
        }

        .expense-table th {
            background-color: #f5f7fa;
            color: #1a237e;
            font-weight: bold;
        }

        .expense-table tr:hover {
            background-color: #f9f9f9;
        }

        .receipt-preview {
            max-width: 100px;
            max-height: 60px;
            cursor: pointer;
            border-radius: 4px;
            border: 1px solid #e0e0e0;
        }

        .summary-section {
            margin-top: 2rem;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
        }

        .summary-card {
            background-color: #f0f2f5;
            padding: 1.5rem;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .summary-total {
            font-size: 1.5rem;
            font-weight: bold;
            color: #1a237e;
        }

        .summary-label {
            color: #555;
            font-size: 0.9rem;
        }

        .category-breakdown {
            margin-top: 1rem;
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
        }

        .alert-error {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ffcdd2;
        }

        .alert-success {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #c8e6c9;
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            overflow: auto;
        }

        .modal-content {
            margin: 5% auto;
            padding: 20px;
            max-width: 800px;
            background-color: #fff;
            border-radius: 8px;
            position: relative;
        }

        .close-modal {
            position: absolute;
            top: 15px;
            right: 20px;
            font-size: 28px;
            cursor: pointer;
        }

        .receipt-image {
            max-width: 100%;
            display: block;
            margin: 0 auto;
        }
    </style>
</head>
<body>
    <div class="main-container" style="min-height: 100vh; background: #f5f7fa; padding: 2rem;">
    <?php include '../../templates/navbar-treasurer.php'; ?>
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem;">
                <h1 style="margin-bottom: 0;">Track Expenses</h1>
                <a href="addExpenses.php" class="btn btn-add">
                    <i class="fas fa-plus"></i> Add New Expense
                </a>
            </div>
            
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error"><?php echo $_SESSION['error_message']; ?></div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success"><?php echo $_SESSION['success_message']; ?></div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            
            <div class="filter-section">
                <h2>Filter Expenses</h2>
                <form action="" method="GET" class="filter-form">
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <option value="">All Categories</option>
                            <option value="Death Welfare" <?php echo $category == 'Death Welfare' ? 'selected' : ''; ?>>Death Welfare</option>
                            <option value="Administrative" <?php echo $category == 'Administrative' ? 'selected' : ''; ?>>Administrative</option>
                            <option value="Utility" <?php echo $category == 'Utility' ? 'selected' : ''; ?>>Utility</option>
                            <option value="Maintenance" <?php echo $category == 'Maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            <option value="Event" <?php echo $category == 'Event' ? 'selected' : ''; ?>>Event</option>
                            <option value="Other" <?php echo $category == 'Other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="method">Payment Method</label>
                        <select id="method" name="method">
                            <option value="">All Methods</option>
                            <option value="Cash" <?php echo $method == 'Cash' ? 'selected' : ''; ?>>Cash</option>
                            <option value="Check" <?php echo $method == 'Check' ? 'selected' : ''; ?>>Check</option>
                            <option value="Bank Transfer" <?php echo $method == 'Bank Transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                            <option value="Digital Payment" <?php echo $method == 'Digital Payment' ? 'selected' : ''; ?>>Digital Payment</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="from_date">From Date</label>
                        <input type="date" id="from_date" name="from_date" value="<?php echo $fromDate; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="to_date">To Date</label>
                        <input type="date" id="to_date" name="to_date" value="<?php echo $toDate; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="term">Term</label>
                        <select id="term" name="term">
                            <option value="">All Terms</option>
                            <?php foreach ($terms as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo $term == $t ? 'selected' : ''; ?>>Term <?php echo $t; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group" style="display: flex; align-items: flex-end;">
                        <button type="submit" class="btn btn-filter">Filter</button>
                        <button type="button" onclick="window.location.href='trackExpenses.php'" class="btn btn-reset" style="margin-left: 0.5rem;">Reset</button>
                    </div>
                </form>
            </div>
            
            <div class="expenses-list">
                <h2>Expenses List</h2>
                
                <?php if ($result && $result->num_rows > 0): ?>
                    <table class="expense-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Date</th>
                                <th>Term</th>
                                <th>Treasurer</th>
                                <th>Receipt</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo $row['ExpenseID']; ?></td>
                                    <td><?php echo $row['Category']; ?></td>
                                    <td>Rs. <?php echo number_format($row['Amount'], 2); ?></td>
                                    <td><?php echo $row['Method']; ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($row['Date'])); ?></td>
                                    <td><?php echo $row['Term']; ?></td>
                                    <td><?php echo $row['TreasurerName']; ?></td>
                                    <td>
                                        <?php if (!empty($row['Image'])): ?>
                                            <img src="../../<?php echo $row['Image']; ?>" alt="Receipt" class="receipt-preview" onclick="showReceiptModal('../../<?php echo $row['Image']; ?>')">
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="viewExpense.php?id=<?php echo $row['ExpenseID']; ?>" class="btn btn-view">
                                            <i class="fas fa-eye"></i>
                                        </a>
                                        
                                        <button class="btn btn-delete" onclick="confirmDelete('<?php echo $row['ExpenseID']; ?>')">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No expenses found.</p>
                <?php endif; ?>
            </div>
            
            <div class="summary-section">
                <div class="summary-card">
                    <div class="summary-label">Total Expenses</div>
                    <div class="summary-total">Rs. <?php echo number_format($totalExpenses, 2); ?></div>
                </div>
                
                <div class="summary-card">
                    <div class="summary-label">Category Breakdown</div>
                    <div class="category-breakdown">
                        <?php foreach ($categoryTotals as $cat => $amount): ?>
                            <div>
                                <strong><?php echo $cat; ?>:</strong> Rs. <?php echo number_format($amount, 2); ?>
                                (<?php echo round(($amount / $totalExpenses) * 100, 1); ?>%)
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Receipt Modal -->
    <div id="receiptModal" class="modal">
        <div class="modal-content">
            <span class="close-modal" onclick="closeReceiptModal()">&times;</span>
            <h2>Receipt Image</h2>
            <img id="fullReceiptImage" src="" alt="Receipt" class="receipt-image">
        </div>
    </div>
    
    <script>
        // Show receipt image in modal
        function showReceiptModal(imageSrc) {
            document.getElementById('fullReceiptImage').src = imageSrc;
            document.getElementById('receiptModal').style.display = 'block';
        }
        
        // Close receipt modal
        function closeReceiptModal() {
            document.getElementById('receiptModal').style.display = 'none';
        }
        
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('receiptModal');
            if (event.target == modal) {
                modal.style.display = 'none';
            }
        }
        
        // Confirm delete
        function confirmDelete(expenseId) {
            if (confirm('Are you sure you want to delete this expense?')) {
                window.location.href = 'trackExpenses.php?delete=true&id=' + expenseId;
            }
        }
    </script>
</body>
</html>