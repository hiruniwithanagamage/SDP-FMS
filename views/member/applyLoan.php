<?php
session_start();
require_once "../../config/database.php";

// Debug database connection
try {
    setupConnection();
    error_log("Database Connection Status: " . ($GLOBALS['db_connection'] ? "Connected" : "Not Connected"));
} catch (Exception $e) {
    error_log("Database Connection Error: " . $e->getMessage());
}

// Initialize error array and current year
$errors = [];
$currentYear = date('Y');

// Check if user is logged in
if (!isset($_SESSION["u"])) {
    header("Location: loginProcess.php");
    exit();
}

// Check database connection
try {
    setupConnection();
    if (!$GLOBALS['db_connection']) {
        die("Database connection failed");
    }
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Get user details from session
$memberId = isset($_SESSION['member_id']) ? $_SESSION['member_id'] : 'N/A';

// Check for existing loan applications in current year
$hasExistingApplication = false;
$existingApplicationStatus = "";
$existingLoanData = null;

if(isset($_SESSION['member_id'])) {
    // Modified to check if there's an active loan (approved with remaining balance)
    // or a pending application for the current term
    $checkQuery = "SELECT Status, Amount, Reason, Issued_Date, Remain_Loan, Remain_Interest FROM Loan 
                  WHERE Member_MemberID = ? 
                  AND Term = ?
                  AND (Status = 'approved' OR Status = 'pending')";
    $stmt = prepare($checkQuery);
    $stmt->bind_param("ss", $_SESSION['member_id'], $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result && $result->num_rows > 0) {
        $existingLoanData = $result->fetch_assoc();
        
        // Check if loan is fully paid (both principal and interest)
        if($existingLoanData['Status'] === 'approved' && 
           $existingLoanData['Remain_Loan'] <= 0 && 
           $existingLoanData['Remain_Interest'] <= 0) {
            // Loan is fully paid, allow new application
            $hasExistingApplication = false;
        } else {
            // Loan is still active or pending
            $hasExistingApplication = true;
            $existingApplicationStatus = $existingLoanData['Status'];
        }
    }
}

// Get member name from database
$memberQuery = "SELECT Name FROM Member WHERE MemberID = '$memberId'";
$memberResult = search($memberQuery);
$userName = 'N/A';
if ($memberResult && $memberResult->num_rows > 0) {
    $memberData = $memberResult->fetch_assoc();
    $userName = $memberData['Name'];
}

// Get loan limits and other settings from Static table
$staticQuery = "SELECT max_loan_limit FROM static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
$staticResult = search($staticQuery);
$maxLoanLimit = 20000; // Default fallback value

if ($staticResult && $staticResult->num_rows > 0) {
    $staticData = $staticResult->fetch_assoc();
    $maxLoanLimit = $staticData['max_loan_limit'];
}

// Validate member eligibility
function checkMemberEligibility($memberId) {
    // Check member status
    $statusQuery = "SELECT Status FROM Member WHERE MemberID = '$memberId'";
    $statusResult = search($statusQuery);
    
    if ($statusResult && $statusResult->num_rows > 0) {
        $member = $statusResult->fetch_assoc();
        if ($member['Status'] !== 'Full Member') {
            return "Member status must be active to apply for a loan";
        }
    }

    // Check existing loans
    $loanQuery = "SELECT Status, Remain_Loan, Remain_Interest FROM Loan 
                  WHERE Member_MemberID = '$memberId' 
                  AND (Status = 'pending' OR Status = 'approved')";
    $loanResult = search($loanQuery);
    
    if ($loanResult && $loanResult->num_rows > 0) {
        $loan = $loanResult->fetch_assoc();
        if ($loan['Status'] === 'pending') {
            return "You already have a pending loan application";
        }
        if ($loan['Status'] === 'approved' && ($loan['Remain_Loan'] > 0 || $loan['Remain_Interest'] > 0)) {
            return "You have an existing loan that needs to be paid off";
        }
    }

    return true;
}

// Generate new Loan ID (loan + Year + 3 digits)
$query = "SELECT LoanID FROM Loan WHERE LoanID LIKE 'LN" . $currentYear . "%' ORDER BY LoanID DESC LIMIT 1";
$result = search($query);

if ($result && $result->num_rows > 0) {
    $lastId = $result->fetch_assoc()['LoanID'];
    // Extract the numeric part after 'LN' + year
    $yearPart = $currentYear;
    $prefix = 'LN' . $yearPart;
    $remainingPart = substr($lastId, strlen($prefix));
    $numericPart = intval($remainingPart);
    $newLoanId = $prefix . sprintf('%02d', $numericPart + 1); // Format with leading zero if needed
} else {
    // First loan of the year
    $newLoanId = "LN" . $currentYear . "01"; // Start with 01
}

// Fetch eligible members for guarantor selection (with guaranteed_count < 1)
$eligibleMembersQuery = "SELECT DISTINCT m.MemberID, m.Name 
                        FROM Member m
                        WHERE m.MemberID != '$memberId' 
                        -- AND m.Status = 'Full Member'
                        AND m.MemberID NOT IN (
                            -- Subquery to find members who are guarantors for active loans
                            SELECT g.MemberID
                            FROM Guarantor g
                            JOIN Loan l ON g.Loan_LoanID = l.LoanID
                            -- WHERE l.Status = 'approved' 
                            WHERE (l.Remain_Loan > 0 OR l.Remain_Interest > 0)
                        )
                        ORDER BY m.Name";
$eligibleMembersResult = search($eligibleMembersQuery);
$eligibleMembers = [];

if ($eligibleMembersResult && $eligibleMembersResult->num_rows > 0) {
    while ($row = $eligibleMembersResult->fetch_assoc()) {
        $eligibleMembers[] = [
            'id' => $row['MemberID'],
            'text' => $row['Name']
        ];
    }
}

// Handle form submission
if (isset($_POST['apply'])) {
    // Debug: Print form data
    error_log("Form Data: " . print_r($_POST, true));

    // Get form data
    $memberId = $_POST['member_id'];
    $amount = floatval($_POST['amount']);
    $reason = trim($_POST['reason']);
    $term = $currentYear;

    $guarantorName = trim($_POST['guarantor_name']);
    $guarantorId = trim($_POST['guarantor_member_id']);
    $guarantorCount = intval($_POST['guarantor_guaranteed_count']);

    // Validation checks
    if ($amount < 500) {
        $errors['amount'] = "Loan amount must be at least Rs. 500";
    }
    
    if ($amount > $maxLoanLimit) {
        $errors['amount'] = "Loan amount cannot exceed Rs. " . number_format($maxLoanLimit, 2);
    }
    
    if (empty($reason)) {
        $errors['reason'] = "Please provide a reason for the loan";
    }
    
    if (empty($guarantorName) || empty($guarantorId)) {
        $errors['guarantor'] = "Guarantor details are required";
    }

    // Check eligibility
    $eligibility = checkMemberEligibility($memberId);
    if ($eligibility !== true) {
        $errors['eligibility'] = $eligibility;
    }

    if (empty($errors)) {
        try {
            $conn = getConnection(); // Get the connection using our procedural function
            $conn->begin_transaction();

            // Calculate dates and interest
            $issuedDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime('+1 year'));

            $interestQuery = "SELECT interest FROM Static WHERE status = 'active' ORDER BY year DESC LIMIT 1";
            $interestResult = search($interestQuery);
            $interestRate = 3; // Default value if query fails
            if ($interestResult && $interestResult->num_rows > 0) {
                $interestData = $interestResult->fetch_assoc();
                $interestRate = $interestData['interest'];
            }
            $initialInterest = $amount * ($interestRate / 100) / 12;

            // Debug: Print calculated values
            error_log("Loan Details: ID=$newLoanId, Amount=$amount, Interest=$initialInterest");

            // Insert loan record
            $loanQuery = "INSERT INTO Loan (LoanID, Amount, Term, Reason, Issued_Date, Due_Date, 
                         Paid_Loan, Remain_Loan, Paid_Interest, Remain_Interest, Member_MemberID, Status, Notification_Seen) 
                         VALUES (
                             '$newLoanId',
                             $amount,
                             $term,
                             '" . $conn->real_escape_string($reason) . "',
                             '$issuedDate',
                             '$dueDate',
                             0,
                             $amount,
                             0,
                             $initialInterest,
                             '$memberId',
                             'pending',
                             0
                         )";
            
            // Debug: Print query
            error_log("Loan Query: " . $loanQuery);
            
            iud($loanQuery); // Already using procedural function

            // Insert guarantor record - ONLY ONE GUARANTOR NOW
            $guarantorPrefix = 'GT'; // Keep as G1 for the single guarantor
            $guarantorId = $guarantorId;
            
            $guarantorQuery = "INSERT INTO Guarantor (GuarantorID, Name, MemberID, Guaranteed_Count, Loan_LoanID) 
                             VALUES (
                                 '{$guarantorPrefix}$newLoanId',
                                 '" . $conn->real_escape_string($guarantorName) . "',
                                 '$guarantorId',
                                 1,
                                 '$newLoanId'
                             )";
            
            // Debug: Print query
            error_log("Guarantor Query: " . $guarantorQuery);
            
            iud($guarantorQuery);

            $conn->commit();
            
            $_SESSION['success_message'] = "Loan application submitted successfully!";
            header("Location: home-member.php?id=" . $newLoanId);
            exit();

        } catch (Exception $e) {
            $conn = getConnection();
            $conn->rollback();
            $errors['db'] = "Error submitting loan application: " . $e->getMessage();
            error_log("Database Error: " . $e->getMessage());
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Loan Application</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="../../assets/css/applyLWF.css">
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-member.php'; ?>
        
        <div class="container">
            <h1>Loan Application</h1>

            <?php if($hasExistingApplication): ?>
                <div class="alert alert-info persistent-alert">
                    <strong>Existing Loan Application Details:</strong><br>
                    Status: <?php echo ucfirst($existingApplicationStatus); ?><br>
                    Amount: Rs. <?php echo number_format($existingLoanData['Amount'], 2); ?><br>
                    Reason: <?php echo htmlspecialchars($existingLoanData['Reason']); ?><br>
                    Applied Date: <?php echo date('Y-m-d', strtotime($existingLoanData['Issued_Date'])); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                    echo $_SESSION['success_message']; 
                    unset($_SESSION['success_message']); // Clear the message after displaying
                    ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors['eligibility'])): ?>
                <div class="alert alert-error">
                    <strong>Unable to apply for loan:</strong> <?php echo $errors['eligibility']; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors['db'])): ?>
                <div class="alert alert-error"><?php echo $errors['db']; ?></div>
            <?php endif; ?>

            <form method="POST" action="" <?php echo $hasExistingApplication ? 'style="display: none;"' : ''; ?>>
                <div class="form-row">
                    <div class="form-group">
                        <label for="member_name">Name</label>
                        <input type="text" id="member_name" name="name" 
                            value="<?php echo htmlspecialchars($userName); ?>" 
                            readonly autocomplete="name">
                    </div>

                    <div class="form-group">
                        <label for="member_id">Member ID</label>
                        <input type="text" id="member_id" name="member_id" 
                            value="<?php echo htmlspecialchars($memberId); ?>" 
                            readonly autocomplete="off">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="issue_date">Date</label>
                        <input type="date" id="issue_date" name="date" 
                            value="<?php echo date('Y-m-d'); ?>" 
                            readonly autocomplete="off">
                    </div>

                    <div class="form-group">
                        <label for="loan_term">Term (Year)</label>
                        <input type="text" id="loan_term" name="term" 
                            value="<?php echo $currentYear; ?>" 
                            readonly autocomplete="off">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="loan_amount">Amount</label>
                        <input type="number" id="loan_amount" name="amount" 
                            min="500" step="0.01" required 
                            max="<?php echo $maxLoanLimit; ?>"
                            class="<?php echo isset($errors['amount']) ? 'error' : ''; ?>"
                            autocomplete="off">
                        <?php if (isset($errors['amount'])): ?>
                            <span class="error-message"><?php echo $errors['amount']; ?></span>
                        <?php endif; ?>
                        <span class="hint-text">Minimum Rs. 500, Maximum Rs. <?php echo number_format($maxLoanLimit, 2); ?></span>
                        <span class="hint-text">3% monthly decreasing interest rate will be applied</span>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group full-width">
                        <label for="loan_reason">Reason</label>
                        <input type="text" id="loan_reason" name="reason" required 
                            class="<?php echo isset($errors['reason']) ? 'error' : ''; ?>"
                            autocomplete="off">
                        <?php if (isset($errors['reason'])): ?>
                            <span class="error-message"><?php echo $errors['reason']; ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Single Guarantor Section -->
                <h2>Guarantor Details</h2>
                <div class="guarantor-section">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="guarantor_name_select">Name</label>
                            <select id="guarantor_name_select" class="guarantor-select" required>
                                <option value="">Select or search for a member...</option>
                                <?php foreach ($eligibleMembers as $member): ?>
                                    <option value="<?php echo $member['id']; ?>"><?php echo $member['text']; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="guarantor_name" name="guarantor_name">
                        </div>
                        <div class="form-group">
                            <label for="guarantor_member_id">Member ID</label>
                            <input type="text" id="guarantor_member_id" name="guarantor_member_id" 
                                readonly required autocomplete="off">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="guarantor_guaranteed_count">Guaranteed Count</label>
                            <input type="number" id="guarantor_guaranteed_count" 
                                name="guarantor_guaranteed_count" 
                                value="0" readonly required autocomplete="off">
                            <span class="hint-text">Members can only guarantee one loan at a time</span>
                        </div>
                    </div>
                </div>

                <div class="terms-group">
                    <input type="checkbox" id="terms_agreement" name="terms" required>
                    <label for="terms_agreement">I agree to the Terms & Conditions</label>
                </div>

                <div class="button-group">
                    <button type="submit" name="apply" class="btn btn-apply" 
                            <?php echo (!empty($errors['eligibility'])) ? 'disabled' : ''; ?>>
                        Apply
                    </button>
                    <a href="home-member.php" class="btn btn-cancel">Cancel</a>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
        // Function to handle alert messages
        function showAlert(message, type = 'error') {
            // Remove any existing alerts
            $('.alert').remove();
            
            // Create new alert
            const alertDiv = $(`<div class="alert alert-${type}"></div>`);
            if (type === 'error') {
                alertDiv.html(`<strong>Error:</strong> ${message}`);
            } else if (type === 'success') {
                alertDiv.html(`<strong>Success:</strong> ${message}`);
            } else {
                alertDiv.text(message);
            }
            
            // Insert alert at the top of the form
            $('h1').after(alertDiv);
            
            // Set timeout to fade out and remove alert
            setTimeout(() => {
                alertDiv.addClass('fade-out');
                setTimeout(() => {
                    alertDiv.remove();
                }, 200);
            }, 3000);
        }

        // Initialize Select2 for guarantor select
        $('.guarantor-select').select2({
            placeholder: 'Select or search for a member...',
            allowClear: true,
            width: '100%',
            dropdownParent: $('body'),
            matcher: function(params, data) {
                // If there are no search terms, return all of the data
                if ($.trim(params.term) === '') {
                    return data;
                }

                // Do not display the item if there is no 'text' property
                if (typeof data.text === 'undefined') {
                    return null;
                }

                // Search both in name and ID
                if (data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1 || 
                    data.id.toLowerCase().indexOf(params.term.toLowerCase()) > -1) {
                    return data;
                }

                // Return `null` if the term should not be displayed
                return null;
            }
        });

        // Handle guarantor selection
        $('#guarantor_name_select').on('select2:select', function(e) {
            const data = e.params.data;
            const name = data.text;
            const memberId = data.id;
            
            $('#guarantor_name').val(name);
            $('#guarantor_member_id').val(memberId);
            // Set guaranteed count to 1 as this will be the first guarantee
            $('#guarantor_guaranteed_count').val(1);
        });

        // Clear member ID when selection is cleared
        $('.guarantor-select').on('select2:clear', function(e) {
            $('#guarantor_name').val('');
            $('#guarantor_member_id').val('');
            $('#guarantor_guaranteed_count').val(0);
        });

        // Form validation
        $('form').on('submit', function(e) {
            const terms = document.getElementById('terms_agreement');
            if (!terms.checked) {
                e.preventDefault();
                showAlert('Please accept the Terms & Conditions');
            }

            // Validate guarantor selection
            const guarantor = $('#guarantor_name_select').val();
            
            if (!guarantor) {
                e.preventDefault();
                showAlert('Please select a guarantor');
            }
            
            // Validate loan amount
            const amount = parseFloat($('#loan_amount').val());
            if (amount < 500) {
                e.preventDefault();
                showAlert('Loan amount must be at least Rs. 500');
            }
            
            const maxLimit = <?php echo $maxLoanLimit; ?>;
            if (amount > maxLimit) {
                e.preventDefault();
                showAlert('Loan amount cannot exceed Rs. ' + maxLimit.toLocaleString());
            }
        });

        // Handle alerts except persistent ones
        $('.alert:not(.persistent-alert)').each(function() {
            const $alert = $(this);
            setTimeout(() => {
                $alert.addClass('fade-out');
                setTimeout(() => {
                    $alert.remove();
                }, 200);
            }, 3000);
        });

        // Additional validation for amount
        // $('#loan_amount').on('input', function() {
        //     const amount = parseFloat($(this).val());
        //     const min = 500;
        //     const max = <?php echo $maxLoanLimit; ?>;
            
        //     if (amount < min) {
        //         $(this).addClass('error');
        //         showAlert('Amount must be at least Rs. 500');
        //     } else if (amount > max) {
        //         $(this).addClass('error');
        //         showAlert('Amount cannot exceed Rs. ' + max.toLocaleString());
        //     } else {
        //         $(this).removeClass('error');
        //     }
        // });
    });
    </script>
</body>
</html>