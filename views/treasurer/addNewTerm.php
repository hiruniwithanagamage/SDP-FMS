<?php
session_start();
require_once "../../config/database.php";

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $year = $_POST['year'];
    $monthly_fee = $_POST['monthly_fee'];
    $registration_fee = $_POST['registration_fee'];
    $death_welfare = $_POST['death_welfare'];
    $late_fine = $_POST['late_fine'];
    $absent_fine = $_POST['absent_fine'];
    $rules_violation_fine = $_POST['rules_violation_fine'];
    $interest = $_POST['interest'];
    $max_loan_limit = $_POST['max_loan_limit'];

    $insertQuery = "INSERT INTO Static (year, monthly_fee, registration_fee, death_welfare, 
                    late_fine, absent_fine, rules_violation_fine, interest, max_loan_limit) 
                    VALUES ('$year', '$monthly_fee', '$registration_fee', '$death_welfare',
                    '$late_fine', '$absent_fine', '$rules_violation_fine', '$interest', '$max_loan_limit')";
    
    try {
        Database::iud($insertQuery);
        $_SESSION['success_message'] = "New term added successfully!";
        header("Location: termDetails.php");
        exit();
    } catch(Exception $e) {
        $_SESSION['error_message'] = "Error adding new term: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Term</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/adminDetails.css">
    <style>
        .form-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            max-width: 800px;
            margin: 2rem auto;
        }

        .form-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            text-decoration: none;
            color: #1e3c72;
            font-weight: 500;
        }

        .back-btn:hover {
            color: #2a5298;
        }

        .form-title {
            font-size: 1.5rem;
            color: #1e3c72;
            margin: 0;
        }

        .alert {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 4px;
        }

        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            gap: 1rem;
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }
        
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?>
        
        <div class="container">
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <div class="form-container">
                <div class="form-header">
                    <h2 class="form-title">Add New Term</h2>
                </div>

                <form method="POST">
                    <div class="form-group">
                        <label for="year">Year</label>
                        <input type="number" id="year" name="year" required min="2024" max="2100">
                    </div>

                    <div class="form-group">
                        <label for="monthly_fee">Monthly Fee (Rs.)</label>
                        <input type="number" id="monthly_fee" name="monthly_fee" required step="0.01">
                    </div>

                    <div class="form-group">
                        <label for="registration_fee">Registration Fee (Rs.)</label>
                        <input type="number" id="registration_fee" name="registration_fee" required step="0.01">
                    </div>

                    <div class="form-group">
                        <label for="death_welfare">Death Welfare Amount (Rs.)</label>
                        <input type="number" id="death_welfare" name="death_welfare" required step="0.01">
                    </div>

                    <div class="form-group">
                        <label for="late_fine">Late Fine (Rs.)</label>
                        <input type="number" id="late_fine" name="late_fine" required step="0.01">
                    </div>

                    <div class="form-group">
                        <label for="absent_fine">Absent Fine (Rs.)</label>
                        <input type="number" id="absent_fine" name="absent_fine" required step="0.01">
                    </div>

                    <div class="form-group">
                        <label for="rules_violation_fine">Rules Violation Fine (Rs.)</label>
                        <input type="number" id="rules_violation_fine" name="rules_violation_fine" required step="0.01">
                    </div>

                    <div class="form-group">
                        <label for="interest">Interest Rate (%)</label>
                        <input type="number" id="interest" name="interest" required step="0.01" max="100">
                    </div>

                    <div class="form-group">
                        <label for="max_loan_limit">Maximum Loan Limit (Rs.)</label>
                        <input type="number" id="max_loan_limit" name="max_loan_limit" required step="0.01">
                    </div>

                    <div class="form-footer">
                        <a href="termDetails.php" class="cancel-btn">Cancel</a>
                        <button type="submit" class="save-btn">Add Term</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Add any client-side validation if needed
        document.querySelector('form').addEventListener('submit', function(e) {
            const inputs = this.querySelectorAll('input[type="number"]');
            let valid = true;

            inputs.forEach(input => {
                if (input.value < 0) {
                    valid = false;
                    input.setCustomValidity('Value cannot be negative');
                } else {
                    input.setCustomValidity('');
                }
            });

            if (!valid) {
                e.preventDefault();
            }
        });
    </script>
</body>
</html>