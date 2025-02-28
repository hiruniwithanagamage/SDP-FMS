<?php
session_start();
require_once "../../../config/database.php";

// Check if payment ID exists in session
if (!isset($_SESSION['last_payment_id'])) {
    header('Location: ../memberPayment.php');
    exit();
}

$paymentId = $_SESSION['last_payment_id'];
unset($_SESSION['last_payment_id']); // Clear the session variable
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payment Receipt</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f6f9;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .receipt-container {
            background-color: white;
            border-radius: 10px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            padding: 30px;
            text-align: center;
            max-width: 500px;
            width: 100%;
        }
        .receipt-actions a {
            display: inline-block;
            margin: 10px;
            padding: 10px 20px;
            background-color: #1e3c72;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: background-color 0.3s;
        }
        .receipt-actions a:hover {
            background-color: #2c5697;
        }
        .receipt-actions a i {
            margin-right: 10px;
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <h2>Payment Processed Successfully!</h2>
        <div class="receipt-actions">
            <a href="payment_receipt.php?payment_id=<?php echo urlencode($paymentId); ?>" target="_blank">
                <i class="fas fa-print"></i>Print Receipt
            </a>
            <a href="payment_receipt.php?payment_id=<?php echo urlencode($paymentId); ?>" target="_blank">
                <i class="fas fa-file-download"></i>Download Receipt
            </a>
            <a href="../memberPayment.php">
                <i class="fas fa-credit-card"></i>Make Another Payment
            </a>
            <a href="../home-member.php">
                <i class="fas fa-home"></i>Back to Dashboard
            </a>
        </div>
    </div>
</body>
</html>