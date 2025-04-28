<?php
session_start();
require "config/database.php";

function checkAndCalculateMonthlyInterest() {
    // Get the current month and year
    $currentMonth = date('m');
    $currentYear = date('Y');
    $currentMonthYear = $currentMonth . '-' . $currentYear;
    
    // Check if we've already calculated interest for this month (using database check)
    $checkSql = "SELECT * FROM InterestCalculationLog WHERE MonthYear = '$currentMonthYear'";
    $checkResult = search($checkSql);
    
    if ($checkResult->num_rows == 0) {
        // Get current interest rate from Static table
        $interestSql = "SELECT interest FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
        $interestResult = search($interestSql);
        
        if ($interestResult && $interestResult->num_rows > 0) {
            $interestRow = $interestResult->fetch_assoc();
            $interestRate = $interestRow['interest'] / 100; // Convert percentage to decimal
            
            // Calculate monthly interest on all active loans with remaining balance
            $loanSql = "SELECT LoanID, Member_MemberID, Remain_Loan, Remain_Interest 
                      FROM Loan 
                      WHERE Status = 'approved' 
                      AND Remain_Loan > 0";
            
            $loanResult = search($loanSql);
            
            if ($loanResult && $loanResult->num_rows > 0) {
                // Using mysqli connection from database.php
                $conn = getConnection();
                
                // Start transaction
                $conn->begin_transaction();
                
                try {
                    $updateCount = 0;
                    while ($loan = $loanResult->fetch_assoc()) {
                        // Calculate monthly interest (simple interest on remaining balance)
                        $monthlyInterest = round($loan['Remain_Loan'] * $interestRate, 2);
                        $newRemainingInterest = $loan['Remain_Interest'] + $monthlyInterest;
                        
                        // Update the loan record
                        $updateSql = "UPDATE Loan 
                                    SET Remain_Interest = ? 
                                    WHERE LoanID = ?";
                        
                        $stmt = $conn->prepare($updateSql);
                        $stmt->bind_param("ds", $newRemainingInterest, $loan['LoanID']);
                        $stmt->execute();
                        
                        if ($stmt->affected_rows > 0) {
                            $updateCount++;
                        }
                    }
                    
                    // Log the calculation in the database
                    $logSql = "INSERT INTO InterestCalculationLog (MonthYear, CalculationDate, LoansUpdated) 
                               VALUES (?, NOW(), ?)";
                    $logStmt = $conn->prepare($logSql);
                    $logStmt->bind_param("si", $currentMonthYear, $updateCount);
                    $logStmt->execute();
                    
                    // Commit the transaction
                    $conn->commit();
                    
                    // Still set session variable for UI notification
                    $_SESSION['interest_calculated_count'] = $updateCount;
                    $_SESSION['interest_just_calculated'] = true;
                    
                    return true;
                } catch (Exception $e) {
                    // Rollback on error
                    $conn->rollback();
                    error_log("Error calculating monthly interest: " . $e->getMessage());
                    return false;
                }
            }
        }
    } else {
        // Interest already calculated for this month
        return false;
    }
    
    return false;
}

$Username = $_POST["u"];
$password = $_POST["p"];

if(empty($Username)){
    echo json_encode(["status" => "error", "message" => "Please enter your Username"]);
}else if(strlen($Username) > 100){
    echo json_encode(["status" => "error", "message" => "Username must have less than 100 characters"]);
}else if (!preg_match("/^[a-zA-Z0-9_]{3,100}$/", $Username)) {
    echo json_encode(["status" => "error", "message" => "Invalid Username"]);
}else if(empty($password)){
    echo json_encode(["status" => "error", "message" => "Please enter your Password"]);
}else if(strlen($password) < 5 || strlen($password) > 20){
    echo json_encode(["status" => "error", "message" => "Invalid Password"]);
}else{
    // Using procedural function
    $rs = search("SELECT u.*, 
            CASE
                WHEN u.Admin_AdminID IS NOT NULL THEN 'admin'
                WHEN u.Member_MemberID IS NOT NULL THEN 'member'
                WHEN u.Treasurer_TreasurerID IS NOT NULL THEN 'treasurer'
                WHEN u.Auditor_AuditorID IS NOT NULL THEN 'auditor'
            END as role,
            COALESCE(u.Admin_AdminID, u.Member_MemberID, u.Treasurer_TreasurerID, u.Auditor_AuditorID) as role_id
        FROM `User` u 
        WHERE `Username`='".$Username."'");
        
    $n = $rs->num_rows;

    if($n == 1){
        $d = $rs->fetch_assoc();

        if ($password == $d['Password']) {
            $_SESSION["u"] = $d;
            $_SESSION["role"] = $d["role"];
            $_SESSION["role_id"] = $d["role_id"];
            $_SESSION["user_id"] = $d["UserId"]; 
            $_SESSION["member_id"] = $d["Member_MemberID"];
            $_SESSION["admin_id"] = $d["Admin_AdminID"];
            $_SESSION["treasurer_id"] = $d["Treasurer_TreasurerID"];
            $_SESSION["auditor_id"] = $d["Auditor_AuditorID"];

            // If treasurer is logging in, calculate interest if needed
            if ($d["role"] == "treasurer" || $d["role"] == "member") {
                checkAndCalculateMonthlyInterest();
            }

            // Return success with role for redirection
            echo json_encode([
                "status" => "success",
                "role" => $d["role"]
            ]);
            
        } else {
            echo json_encode(["status" => "error", "message" => "Invalid Username or Password"]);
        }
    }else{
        echo json_encode(["status" => "error", "message" => "Invalid Username or Password"]);
    } 
}
?>