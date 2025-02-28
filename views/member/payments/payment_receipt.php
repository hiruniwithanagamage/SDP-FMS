<?php
session_start();
require_once "../../../config/database.php";

// Check if payment ID is provided
if (!isset($_GET['payment_id'])) {
    die("No payment ID provided");
}

$paymentId = $_GET['payment_id'];

// Fetch comprehensive payment details
$paymentQuery = "
    SELECT 
        p.PaymentID, 
        p.Amount, 
        p.Date, 
        p.Payment_Type,
        p.Method,
        m.Name AS MemberName,
        m.MemberID,
        p.Term
    FROM Payment p
    JOIN Member m ON p.Member_MemberID = m.MemberID
    WHERE p.PaymentID = '$paymentId'
";
$paymentResult = Database::search($paymentQuery);

if (!$paymentResult || $paymentResult->num_rows == 0) {
    die("Payment not found");
}

$paymentDetails = $paymentResult->fetch_assoc();

// Fetch additional details based on payment type
function getAdditionalPaymentDetails($paymentId, $paymentType) {
    switch ($paymentType) {
        case 'monthly':
            $query = "
                SELECT GROUP_CONCAT(DISTINCT MONTHNAME(mf.Date)) as months 
                FROM MembershipFee mf
                JOIN MembershipFeePayment mfp ON mf.FeeID = mfp.FeeID
                WHERE mfp.PaymentID = '$paymentId'
            ";
            break;
        case 'registration':
            $query = "
                SELECT 'Registration Fee' as details
            ";
            break;
        case 'loan':
            $query = "
                SELECT l.LoanID, l.Remain_Loan, l.Remain_Interest
                FROM LoanPayment lp
                JOIN Loan l ON lp.LoanID = l.LoanID
                WHERE lp.PaymentID = '$paymentId'
            ";
            break;
        case 'fine':
            $query = "
                SELECT Description 
                FROM Fine 
                WHERE Payment_PaymentID = '$paymentId'
            ";
            break;
        default:
            return null;
    }

    $result = Database::search($query);
    return $result ? $result->fetch_assoc() : null;
}

$additionalDetails = getAdditionalPaymentDetails($paymentId, $paymentDetails['Payment_Type']);
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
            flex-direction: column;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            margin: 0;
            padding: 20px;
        }

        .receipt-container {
            background-color: white;
            border: 1px solid #ddd;
            padding: 30px;
            width: 100%;
            max-width: 600px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }

        .receipt-header {
            text-align: center;
            border-bottom: 1px solid #ddd;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .receipt-header h1 {
            margin: 0;
            color: #333;
            font-size: 24px;
        }

        .receipt-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }

        .receipt-amount {
            text-align: center;
            padding: 15px;
            border: 1px solid #ddd;
            margin: 20px 0;
            font-weight: bold;
            font-size: 18px;
        }

        .receipt-footer {
            text-align: center;
            padding-top: 15px;
            border-top: 1px solid #ddd;
            color: #666;
        }

        .print-actions {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }

        .print-btn {
            padding: 10px 20px;
            background-color: #1e3c72;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            border: none;
            cursor: pointer;
        }

        .print-btn:hover {
            background-color: #2c4a7c;
        }

        @media print {
            .print-actions {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Printable Receipt Container -->
    <div class="receipt-container" id="receipt-content">
        <div class="receipt-header">
            <h1>Payment Receipt</h1>
            <p>Receipt No: <?php echo htmlspecialchars($paymentDetails['PaymentID']); ?></p>
        </div>

        <div class="receipt-details">
            <div>
                <div class="detail-row">
                    <strong>Member Name:</strong>
                    <span><?php echo htmlspecialchars($paymentDetails['MemberName']); ?></span>
                </div>
                <div class="detail-row">
                    <strong>Member ID:</strong>
                    <span><?php echo htmlspecialchars($paymentDetails['MemberID']); ?></span>
                </div>
                <div class="detail-row">
                    <strong>Payment Date:</strong>
                    <span><?php echo date('Y-m-d', strtotime($paymentDetails['Date'])); ?></span>
                </div>
            </div>
            <div>
                <div class="detail-row">
                    <strong>Payment Type:</strong>
                    <span><?php echo ucfirst(htmlspecialchars($paymentDetails['Payment_Type'])); ?></span>
                </div>
                <div class="detail-row">
                    <strong>Payment Method:</strong>
                    <span><?php echo ucfirst(htmlspecialchars($paymentDetails['Method'])); ?></span>
                </div>
                <div class="detail-row">
                    <strong>Additional Details:</strong>
                    <span>
                        <?php 
                        if ($additionalDetails) {
                            switch ($paymentDetails['Payment_Type']) {
                                case 'monthly':
                                    echo htmlspecialchars($additionalDetails['months'] ?? 'N/A');
                                    break;
                                case 'loan':
                                    echo "Loan Remaining: Rs. " . 
                                         number_format($additionalDetails['Remain_Loan'] ?? 0, 2);
                                    break;
                                case 'fine':
                                    echo htmlspecialchars($additionalDetails['Description'] ?? 'N/A');
                                    break;
                                default:
                                    echo 'N/A';
                            }
                        } else {
                            echo 'N/A';
                        }
                        ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="receipt-amount">
            Total Amount: Rs. <?php echo number_format($paymentDetails['Amount'], 2); ?>
        </div>

        <div class="receipt-footer">
            <p>Thank you for your payment!</p>
            <p>Term <?php echo htmlspecialchars($paymentDetails['Term']); ?> | Official Receipt</p>
        </div>
    </div>

    <!-- Buttons Section -->
    <div class="print-actions">
        <button class="print-btn" onclick="window.print()">
            <i class="fas fa-print"></i> Print Receipt
        </button>
        <a href="#" class="print-btn" id="downloadPdfBtn">
            <i class="fas fa-file-download"></i> Download PDF
        </a>
        <a href="../memberPayment.php" class="print-btn">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>

    <!-- PDF Generation Libraries -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="https://html2canvas.hertzen.com/dist/html2canvas.min.js"></script>
    <script>
    document.getElementById('downloadPdfBtn').addEventListener('click', function(e) {
        e.preventDefault();
        const { jsPDF } = window.jspdf;
        const doc = new jsPDF();
        
        // Hide print actions before capturing
        document.querySelector('.print-actions').style.display = 'none';
        
        html2canvas(document.getElementById('receipt-content'), {
            scale: 2,
            useCORS: true
        }).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const imgProps = doc.getImageProperties(imgData);
            const pdfWidth = doc.internal.pageSize.getWidth();
            const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
            
            doc.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
            doc.save(`Receipt_<?php echo $paymentId; ?>.pdf`);
            
            // Restore print actions after PDF generation
            document.querySelector('.print-actions').style.display = 'flex';
        }).catch(error => {
            console.error('Error generating PDF:', error);
            alert('Failed to generate PDF. Please try again.');
            
            // Ensure print actions are visible even if there's an error
            document.querySelector('.print-actions').style.display = 'flex';
        });
    });
    </script>
</body>
</html>