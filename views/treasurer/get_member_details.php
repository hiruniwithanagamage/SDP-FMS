<?php
require_once "../../config/database.php";

if (!isset($_GET['member_id'])) {
    header('Content-Type: application/json');
    http_response_code(400);
    echo json_encode(['error' => 'Member ID is required']);
    exit();
}

$memberId = $_GET['member_id'];
$currentYear = date('Y');

try {
    // Get basic member details
    $query = "SELECT 
                m.MemberID,
                m.Name,
                m.Status,
                m.Joined_Date
              FROM Member m
              WHERE m.MemberID = '$memberId'";
              
    $result = search($query);
    
    if ($result->num_rows === 0) {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['error' => 'Member not found']);
        exit();
    }
    
    $memberData = $result->fetch_assoc();

    // Get registration fee status
    $regFeeQuery = "SELECT 
                        COALESCE(SUM(p.Amount), 0) as paid_amount
                    FROM MembershipFee mf
                    LEFT JOIN MembershipFeePayment mfp ON mf.FeeID = mfp.FeeID
                    LEFT JOIN Payment p ON mfp.PaymentID = p.PaymentID
                    WHERE mf.Member_MemberID = '$memberId'
                    AND mf.Type = 'registration'
                    AND mf.Term = $currentYear";
                    
    $regFeeResult = search($regFeeQuery);
    $regFeePaid = $regFeeResult->fetch_assoc()['paid_amount'];

    // Get current fee structure
    $staticQuery = "SELECT * FROM Static WHERE year = $currentYear";
    $staticResult = search($staticQuery);
    $feeStructure = $staticResult->fetch_assoc();

    // Get monthly fee payment status
    $monthlyFeeQuery = "SELECT 
                            MONTH(mf.Date) as month,
                            mf.IsPaid
                        FROM MembershipFee mf
                        WHERE mf.Member_MemberID = '$memberId'
                        AND mf.Type = 'monthly'
                        AND YEAR(mf.Date) = $currentYear";
                        
    $monthlyFeeResult = search($monthlyFeeQuery);
    $paidMonths = [];
    while ($row = $monthlyFeeResult->fetch_assoc()) {
        if ($row['IsPaid'] === 'Yes') {
            $paidMonths[] = (int)$row['month'];
        }
    }

    // Get unpaid fines
    $finesQuery = "SELECT 
                    FineID,
                    Amount,
                    Date,
                    Description
                   FROM Fine
                   WHERE Member_MemberID = '$memberId'
                   AND IsPaid = 'No'
                   ORDER BY Date ASC";
                   
    $finesResult = search($finesQuery);
    $unpaidFines = [];
    while ($row = $finesResult->fetch_assoc()) {
        $unpaidFines[] = $row;
    }

    // Get active loans
    $loansQuery = "SELECT 
                    LoanID,
                    Amount,
                    Remain_Loan,
                    Remain_Interest,
                    Due_Date
                   FROM Loan
                   WHERE Member_MemberID = '$memberId'
                   AND Status = 'approved'
                   AND (Remain_Loan > 0 OR Remain_Interest > 0)
                   ORDER BY Due_Date ASC";
                   
    $loansResult = search($loansQuery);
    $activeLoans = [];
    while ($row = $loansResult->fetch_assoc()) {
        $activeLoans[] = $row;
    }

    // Calculate remaining registration fee
    $remainingRegFee = $feeStructure['registration_fee'] - $regFeePaid;

    // Prepare response
    $response = [
        'member_id' => $memberData['MemberID'],
        'name' => $memberData['Name'],
        'status' => $memberData['Status'],
        'joined_date' => $memberData['Joined_Date'],
        'registration_fee' => [
            'total' => $feeStructure['registration_fee'],
            'paid' => $regFeePaid,
            'remaining' => $remainingRegFee
        ],
        'monthly_fee' => [
            'amount' => $feeStructure['monthly_fee'],
            'paid_months' => $paidMonths
        ],
        'unpaid_fines' => $unpaidFines,
        'active_loans' => $activeLoans,
        'payment_options' => [
            'registration' => $remainingRegFee > 0,
            'monthly' => true,
            'fine' => count($unpaidFines) > 0,
            'loan' => count($activeLoans) > 0
        ]
    ];

    // Send response
    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    // Log error
    error_log("Error in get_member_details.php: " . $e->getMessage());
    
    // Return error response
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'error' => 'An error occurred while fetching member details',
        'message' => $e->getMessage()
    ]);
}
?>