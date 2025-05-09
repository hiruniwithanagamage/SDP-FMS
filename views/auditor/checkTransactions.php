<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
session_start();
require_once "../../config/database.php";

// Check if user is logged in and is an auditor
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'auditor') {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Check if the required parameters are present
if (!isset($_POST['reportId']) || !isset($_POST['versionId']) || !isset($_POST['term'])) {
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required parameters']);
    exit();
}

$reportId = $_POST['reportId'];
$versionId = $_POST['versionId'];
$term = $_POST['term'];

// Helper function to check for new records in a specific table
function checkNewRecords($tableName, $dateColumn, $reportDate, $term) {
    $sql = "SELECT COUNT(*) as count FROM $tableName 
            WHERE $dateColumn > ? AND Term = ?";
    $stmt = prepare($sql);
    $stmt->bind_param("si", $reportDate, $term);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    return $row['count'];
}

// Get the creation date of the current report version
$getReportDateSql = "SELECT Date FROM FinancialReportVersions 
                     WHERE ReportID = ? AND VersionID = ?";
$getReportDateStmt = prepare($getReportDateSql);
$getReportDateStmt->bind_param("ss", $reportId, $versionId);
$getReportDateStmt->execute();
$reportDateResult = $getReportDateStmt->get_result();

$response = ['hasNewTransactions' => false];

if ($reportDateResult->num_rows > 0) {
    $reportDateRow = $reportDateResult->fetch_assoc();
    $reportDate = $reportDateRow['Date'];
    
    // Check for new loans after report date
    $newLoansCount = checkNewRecords("Loan", "Issued_Date", $reportDate, $term);
    
    // Check for new payments after report date
    $newPaymentsCount = checkNewRecords("Payment", "Date", $reportDate, $term);
    
    // Check for new membership fees after report date
    $newFeesCount = checkNewRecords("MembershipFee", "Date", $reportDate, $term);
    
    // Check for new fines after report date
    $newFinesCount = checkNewRecords("Fine", "Date", $reportDate, $term);
    
    // Check for new welfare payments after report date
    $newWelfareCount = checkNewRecords("DeathWelfare", "Date", $reportDate, $term);
    
    // Check for new expenses after report date
    $newExpensesCount = checkNewRecords("Expenses", "Date", $reportDate, $term);
    
    // Total new transactions
    $totalNewTransactions = $newLoansCount + $newPaymentsCount + $newFeesCount + 
                           $newFinesCount + $newWelfareCount + $newExpensesCount;
    
    $response = [
        'hasNewTransactions' => $totalNewTransactions > 0,
        'totalNewTransactions' => $totalNewTransactions,
        'details' => [
            'loans' => $newLoansCount,
            'payments' => $newPaymentsCount,
            'fees' => $newFeesCount,
            'fines' => $newFinesCount,
            'welfare' => $newWelfareCount,
            'expenses' => $newExpensesCount
        ]
    ];
}

header('Content-Type: application/json');
echo json_encode($response);
exit();