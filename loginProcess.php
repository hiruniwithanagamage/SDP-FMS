<?php
// Prevent any output before JSON response
ob_start();

// Disable error display (but still log errors)
ini_set('display_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

// Set content type to JSON
header('Content-Type: application/json');

session_start();
require "config/database.php";

// Security constants
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 15); // minutes

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
                        
                        $stmt = prepare($updateSql);
                        $stmt->bind_param("ds", $newRemainingInterest, $loan['LoanID']);
                        $stmt->execute();
                        
                        if ($stmt->affected_rows > 0) {
                            $updateCount++;
                        }
                    }
                    
                    // Log the calculation in the database
                    $logSql = "INSERT INTO InterestCalculationLog (MonthYear, CalculationDate, LoansUpdated) 
                               VALUES (?, NOW(), ?)";
                    $logStmt = prepare($logSql);
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

// Function to check account lockout status
function isAccountLocked($userId) {
    $query = "SELECT locked_until FROM User WHERE UserId = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        if ($row['locked_until'] && strtotime($row['locked_until']) > time()) {
            return true;
        }
    }
    return false;
}

// Function to record failed login attempt
function recordFailedAttempt($username) {
    $checkQuery = "SELECT UserId FROM User WHERE Username = BINARY ?";
    $checkStmt = prepare($checkQuery);
    $checkStmt->bind_param("s", $username);
    $checkStmt->execute();
    $checkResult = $checkStmt->get_result();
    
    if ($checkResult->num_rows == 0) {
        return; // Skip if user doesn't exist
    }
    
    $query = "UPDATE User 
              SET failed_attempts = failed_attempts + 1,
                  locked_until = IF(failed_attempts + 1 >= ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), NULL)
              WHERE Username = BINARY ?";
              
    $stmt = prepare($query);
    $maxAttempts = MAX_LOGIN_ATTEMPTS;
    $lockoutDuration = LOCKOUT_DURATION;
    $stmt->bind_param("iis", $maxAttempts, $lockoutDuration, $username);
    $stmt->execute();
}

// Function to reset login attempts
function resetLoginAttempts($userId) {
    $query = "UPDATE User 
              SET failed_attempts = 0, 
                  locked_until = NULL 
              WHERE UserId = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $userId);
    $stmt->execute();
}

// Function to log authentication attempts
function logLoginAttempt($userId, $username, $success, $failureReason = null) {
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    $query = "INSERT INTO login_audit_log 
              (UserId, username_attempt, success, ip_address, user_agent, failure_reason) 
              VALUES (?, ?, ?, ?, ?, ?)";
              
    $stmt = prepare($query);
    $stmt->bind_param("ssisss", $userId, $username, $success, $ipAddress, $userAgent, $failureReason);
    $stmt->execute();
}

// Function to check if a treasurer is eligible to login
function isTreasurerEligible($treasurerId) {
    // First get treasurer info
    $query = "SELECT t.isActive, t.Term FROM Treasurer t WHERE t.TreasurerID = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $treasurerId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return false; // Treasurer not found
    }
    
    $treasurer = $result->fetch_assoc();
    
    // Get current active term from Static table
    $termQuery = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $termResult = search($termQuery);
    
    if ($termResult->num_rows == 0) {
        return false; // No active term found
    }
    
    $activeTerm = $termResult->fetch_assoc()['year'];
    
    // Case 1: Treasurer is active AND their term matches the current active term
    if ($treasurer['isActive'] == 1 && $treasurer['Term'] == $activeTerm) {
        return true;
    }
    
    // Case 2: Check if there's an active treasurer for the current term
    $activeQuery = "SELECT COUNT(*) as count FROM Treasurer WHERE Term = ? AND isActive = 1";
    $activeStmt = prepare($activeQuery);
    $activeStmt->bind_param("i", $activeTerm);
    $activeStmt->execute();
    $activeResult = $activeStmt->get_result();
    $activeCount = $activeResult->fetch_assoc()['count'];
    
    // If no active treasurer for current term and this treasurer is from previous term
    if ($activeCount == 0 && $treasurer['Term'] < $activeTerm) {
        return true; // Previous treasurer can log in
    }
    
    return false; // In all other cases, treasurer cannot log in
}

// Function to check if an auditor is eligible to login
function isAuditorEligible($auditorId) {
    // Get auditor info
    $query = "SELECT a.isActive, a.Term FROM Auditor a WHERE a.AuditorID = ?";
    $stmt = prepare($query);
    $stmt->bind_param("s", $auditorId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        return false; // Auditor not found
    }
    
    $auditor = $result->fetch_assoc();
    
    // Get current active term from Static table
    $termQuery = "SELECT year FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
    $termResult = search($termQuery);
    
    if ($termResult->num_rows == 0) {
        return false; // No active term found
    }
    
    $activeTerm = $termResult->fetch_assoc()['year'];
    
    // Case 1: Auditor is active AND their term matches the current active term
    if ($auditor['isActive'] == 1 && $auditor['Term'] == $activeTerm) {
        return true;
    }
    
    // Case 2: Check if there's an active auditor for the current term
    $activeQuery = "SELECT COUNT(*) as count FROM Auditor WHERE Term = ? AND isActive = 1";
    $activeStmt = prepare($activeQuery);
    $activeStmt->bind_param("i", $activeTerm);
    $activeStmt->execute();
    $activeResult = $activeStmt->get_result();
    $activeCount = $activeResult->fetch_assoc()['count'];
    
    // If no active auditor for current term and this auditor is from previous term
    if ($activeCount == 0 && $auditor['Term'] < $activeTerm) {
        return true; // Previous auditor can log in
    }
    
    return false; // In all other cases, auditor cannot log in
}

try {
    // Get inputs
    $Username = $_POST["u"] ?? '';
    $password = $_POST["p"] ?? '';

    // Simple validation
    if (empty($Username)) {
        throw new Exception("Please enter your Username");
    }

    if (empty($password)) {
        throw new Exception("Please enter your Password");
    }

    // Get validated values
    $validUsername = trim($Username);
    $validPassword = trim($password);

    // Check if account exists and get info
    $query = "SELECT u.*, 
                CASE
                    WHEN u.Admin_AdminID IS NOT NULL THEN 'admin'
                    WHEN u.Member_MemberID IS NOT NULL THEN 'member'
                    WHEN u.Treasurer_TreasurerID IS NOT NULL THEN 'treasurer'
                    WHEN u.Auditor_AuditorID IS NOT NULL THEN 'auditor'
                END as role,
                COALESCE(u.Admin_AdminID, u.Member_MemberID, u.Treasurer_TreasurerID, u.Auditor_AuditorID) as role_id,
                u.failed_attempts, u.locked_until
            FROM `User` u 
            WHERE BINARY `Username` = ?";
            
    $stmt = prepare($query);
    $stmt->bind_param("s", $validUsername);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $d = $result->fetch_assoc();
        
        // Check if account is locked
        if (isAccountLocked($d['UserId'])) {
            $remainingTime = ceil((strtotime($d['locked_until']) - time()) / 60);
            throw new Exception("Account locked due to too many failed attempts. Please try again in $remainingTime minutes.");
        }
        
        // Check password
        $passwordCorrect = false;
        $isHashed = strlen($d['Password']) > 20 && strpos($d['Password'], '$') === 0;
        
        if ($isHashed) {
            $passwordCorrect = password_verify($validPassword, $d['Password']);
        } else {
            $passwordCorrect = ($validPassword == $d['Password']);
        }
        
        if ($passwordCorrect) {
            // Check role-specific eligibility
            if ($d["role"] == "treasurer") {
                if (!isTreasurerEligible($d["Treasurer_TreasurerID"])) {
                    throw new Exception("Your treasurer account is not active for the current term.");
                }
            } elseif ($d["role"] == "auditor") {
                if (!isAuditorEligible($d["Auditor_AuditorID"])) {
                    throw new Exception("Your auditor account is not active for the current term.");
                }
            }
            
            // Reset login attempts on successful login
            resetLoginAttempts($d['UserId']);
            
            // Set session variables
            $_SESSION["u"] = $d;
            $_SESSION["role"] = $d["role"];
            $_SESSION["role_id"] = $d["role_id"];
            $_SESSION["user_id"] = $d["UserId"];
            $_SESSION["member_id"] = $d["Member_MemberID"];
            $_SESSION["admin_id"] = $d["Admin_AdminID"];
            $_SESSION["treasurer_id"] = $d["Treasurer_TreasurerID"];
            $_SESSION["auditor_id"] = $d["Auditor_AuditorID"];
            
            // Get the user's last login time
            $lastLogin = $d["last_login"] ?? null;
            
            // Update last login time
            $updateLoginSql = "UPDATE `User` SET last_login = NOW() WHERE `Username` = ?";
            $updateStmt = prepare($updateLoginSql);
            $updateStmt->bind_param("s", $validUsername);
            $updateStmt->execute();
            
            // Clear output buffer and send JSON response
            ob_end_clean();
            echo json_encode([
                "status" => "success",
                "role" => $d["role"],
                "lastLogin" => $lastLogin
            ]);
            exit;
            
        } else {
            // Record failed attempt
            recordFailedAttempt($validUsername);
            
            $failedAttempts = $d['failed_attempts'] + 1;
            $remainingAttempts = MAX_LOGIN_ATTEMPTS - $failedAttempts;
            
            if ($remainingAttempts > 0) {
                throw new Exception("Invalid Username or Password. $remainingAttempts attempts remaining.");
            } else {
                throw new Exception("Account locked due to too many failed attempts. Please try again in " . LOCKOUT_DURATION . " minutes.");
            }
        }
    } else {
        throw new Exception("Invalid Username or Password");
    }
    
} catch (Exception $e) {
    // Clear output buffer and send error response
    ob_end_clean();
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => $e->getMessage()
    ]);
    exit;
}

// Clear output buffer to prevent any leftover content
ob_end_clean();
?>