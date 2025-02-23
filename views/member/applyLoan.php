<?php
session_start();
require_once "../../config/database.php";

// Debug database connection
try {
    Database::setUpConnection();
    error_log("Database Connection Status: " . (Database::$connection ? "Connected" : "Not Connected"));
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
    Database::setUpConnection();
    if (!Database::$connection) {
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
    $checkQuery = "SELECT Status, Amount, Reason, Issued_Date FROM Loan 
                  WHERE Member_MemberID = ? 
                  AND Term = ?
                  AND (Status = 'approved' OR Status = 'pending')";
    $stmt = Database::$connection->prepare($checkQuery);
    $stmt->bind_param("ss", $_SESSION['member_id'], $currentYear);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($result && $result->num_rows > 0) {
        $hasExistingApplication = true;
        $existingLoanData = $result->fetch_assoc();
        $existingApplicationStatus = $existingLoanData['Status'];
    }
}

// Get member name from database
$memberQuery = "SELECT Name FROM Member WHERE MemberID = '$memberId'";
$memberResult = Database::search($memberQuery);
$userName = 'N/A';
if ($memberResult && $memberResult->num_rows > 0) {
    $memberData = $memberResult->fetch_assoc();
    $userName = $memberData['Name'];
}

// Validate member eligibility
function checkMemberEligibility($memberId) {
    // Check member status
    $statusQuery = "SELECT Status FROM Member WHERE MemberID = '$memberId'";
    $statusResult = Database::search($statusQuery);
    
    if ($statusResult && $statusResult->num_rows > 0) {
        $member = $statusResult->fetch_assoc();
        if ($member['Status'] !== 'Full Member') {
            return "Member status must be active to apply for a loan";
        }
    }

    // Check existing loans
    $loanQuery = "SELECT Status, Remain_Loan FROM Loan 
                  WHERE Member_MemberID = '$memberId' 
                  AND (Status = 'pending' OR Status = 'approved')";
    $loanResult = Database::search($loanQuery);
    
    if ($loanResult && $loanResult->num_rows > 0) {
        $loan = $loanResult->fetch_assoc();
        if ($loan['Status'] === 'pending') {
            return "You already have a pending loan application";
        }
        if ($loan['Status'] === 'approved' && $loan['Remain_Loan'] > 0) {
            return "You have an existing loan that needs to be paid off";
        }
    }

    return true;
}

// Generate new Loan ID (L + Year + 3 digits)
$query = "SELECT LoanID FROM Loan WHERE LoanID LIKE 'L" . $currentYear . "%' ORDER BY LoanID DESC LIMIT 1";
$result = Database::search($query);

if ($result->num_rows > 0) {
    $lastId = $result->fetch_assoc()['LoanID'];
    $numericPart = intval(substr($lastId, 4));
    $newLoanId = "loan" . ($numericPart + 1);
} else {
    $newLoanId = "loan1";
}

// Fetch all eligible members for guarantor selection
$eligibleMembersQuery = "SELECT MemberID, Name FROM Member 
                        WHERE MemberID != '$memberId'
                        ORDER BY Name";
$eligibleMembersResult = Database::search($eligibleMembersQuery);
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

    $guarantor1Name = trim($_POST['guarantor1_name']);
    $guarantor1Id = trim($_POST['guarantor1_member_id']);
    $guarantor1Status = $_POST['guarantor1_loan_status'];
    $guarantor1Count = intval($_POST['guarantor1_guaranteed_count']);

    $guarantor2Name = trim($_POST['guarantor2_name']);
    $guarantor2Id = trim($_POST['guarantor2_member_id']);
    $guarantor2Status = $_POST['guarantor2_loan_status'];
    $guarantor2Count = intval($_POST['guarantor2_guaranteed_count']);

    // Validation checks
    if ($amount <= 0) {
        $errors['amount'] = "Please enter a valid amount";
    }
    
    if (empty($reason)) {
        $errors['reason'] = "Please provide a reason for the loan";
    }
    
    if (empty($guarantor1Name) || empty($guarantor1Id)) {
        $errors['guarantor1'] = "First guarantor details are required";
    }
    
    if (empty($guarantor2Name) || empty($guarantor2Id)) {
        $errors['guarantor2'] = "Second guarantor details are required";
    }
    
    if ($guarantor1Id === $guarantor2Id) {
        $errors['guarantors'] = "Guarantors must be different members";
    }

    // Check eligibility
    $eligibility = checkMemberEligibility($memberId);
    if ($eligibility !== true) {
        $errors['eligibility'] = $eligibility;
    }

    if (empty($errors)) {
        try {
            Database::$connection->begin_transaction();

            // Calculate dates and interest
            $issuedDate = date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime('+1 year'));
            $initialInterest = $amount * 0.03 * 12; // 3% monthly for 12 months

            // Debug: Print calculated values
            error_log("Loan Details: ID=$newLoanId, Amount=$amount, Interest=$initialInterest");

            // Insert loan record
            $loanQuery = "INSERT INTO Loan (LoanID, Amount, Term, Reason, Issued_Date, Due_Date, 
                         Paid_Loan, Remain_Loan, Paid_Interest, Remain_Interest, Member_MemberID, Status, Notification_Seen) 
                         VALUES (
                             '$newLoanId',
                             $amount,
                             $term,
                             '" . Database::$connection->real_escape_string($reason) . "',
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
            
            Database::iud($loanQuery);

            // Insert guarantor records
            $guarantors = [
                [
                    'id' => $guarantor1Id,
                    'name' => $guarantor1Name,
                    'status' => $guarantor1Status,
                    'count' => $guarantor1Count,
                    'prefix' => 'G1'
                ],
                [
                    'id' => $guarantor2Id,
                    'name' => $guarantor2Name,
                    'status' => $guarantor2Status,
                    'count' => $guarantor2Count,
                    'prefix' => 'G2'
                ]
            ];

            foreach ($guarantors as $g) {
                $guarantorQuery = "INSERT INTO Guarantor (GuarantorID, Name, MemberID, Loan_Status, 
                                 Guaranteed_Count, Loan_LoanID) 
                                 VALUES (
                                     '{$g['prefix']}$newLoanId',
                                     '" . Database::$connection->real_escape_string($g['name']) . "',
                                     '" . $g['id'] . "',
                                     {$g['status']},
                                     {$g['count']},
                                     '$newLoanId'
                                 )";
                
                // Debug: Print query
                error_log("Guarantor Query: " . $guarantorQuery);
                
                Database::iud($guarantorQuery);
            }

            Database::$connection->commit();
            
            $_SESSION['success_message'] = "Loan application submitted successfully!";
            header("Location: home-member.php?id=" . $newLoanId);
            exit();

        } catch (Exception $e) {
            Database::$connection->rollback();
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
                <!-- Original form fields with fixes -->
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
               min="0" step="0.01" required 
               class="<?php echo isset($errors['amount']) ? 'error' : ''; ?>"
               autocomplete="off">
        <?php if (isset($errors['amount'])): ?>
            <span class="error-message"><?php echo $errors['amount']; ?></span>
        <?php endif; ?>
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

<!-- Guarantor 1 Section -->
<h2>Guarantor Details (1)</h2>
<div class="guarantor-section">
    <div class="form-row">
        <div class="form-group">
            <label for="guarantor1_name_select">Name</label>
            <select id="guarantor1_name_select" class="guarantor-select" required>
                <option value="">Select or search for a member...</option>
                <?php foreach ($eligibleMembers as $member): ?>
                    <option value="<?php echo $member['id']; ?>"><?php echo $member['text']; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" id="guarantor1_name" name="guarantor1_name">
        </div>
        <div class="form-group">
            <label for="guarantor1_member_id">Member ID</label>
            <input type="text" id="guarantor1_member_id" name="guarantor1_member_id" 
                   readonly required autocomplete="off">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="guarantor1_loan_status">Loan Status</label>
            <select id="guarantor1_loan_status" name="guarantor1_loan_status" required>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
            </select>
        </div>
        <div class="form-group">
            <label for="guarantor1_guaranteed_count">Guaranteed Count</label>
            <input type="number" id="guarantor1_guaranteed_count" 
                   name="guarantor1_guaranteed_count" 
                   min="0" value="0" required autocomplete="off">
        </div>
    </div>
</div>

<!-- Guarantor 2 Section -->
<h2>Guarantor Details (2)</h2>
<div class="guarantor-section">
    <div class="form-row">
        <div class="form-group">
            <label for="guarantor2_name_select">Name</label>
            <select id="guarantor2_name_select" class="guarantor-select" required>
                <option value="">Select or search for a member...</option>
                <?php foreach ($eligibleMembers as $member): ?>
                    <option value="<?php echo $member['id']; ?>"><?php echo $member['text']; ?></option>
                <?php endforeach; ?>
            </select>
            <input type="hidden" id="guarantor2_name" name="guarantor2_name">
        </div>
        <div class="form-group">
            <label for="guarantor2_member_id">Member ID</label>
            <input type="text" id="guarantor2_member_id" name="guarantor2_member_id" 
                   readonly required autocomplete="off">
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label for="guarantor2_loan_status">Loan Status</label>
            <select id="guarantor2_loan_status" name="guarantor2_loan_status" required>
                <option value="1">Active</option>
                <option value="0">Inactive</option>
            </select>
        </div>
        <div class="form-group">
            <label for="guarantor2_guaranteed_count">Guaranteed Count</label>
            <input type="number" id="guarantor2_guaranteed_count" 
                   name="guarantor2_guaranteed_count" 
                   min="0" value="0" required autocomplete="off">
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

        // Initialize Select2 for both guarantor selects
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

        // Handle guarantor 1 selection
        $('#guarantor1_name_select').on('select2:select', function(e) {
            const data = e.params.data;
            const name = data.text;
            const memberId = data.id;
            
            $('#guarantor1_name').val(name);
            $('#guarantor1_member_id').val(memberId);
        });

        // Handle guarantor 2 selection
        $('#guarantor2_name_select').on('select2:select', function(e) {
            const data = e.params.data;
            const name = data.text;
            const memberId = data.id;
            
            $('#guarantor2_name').val(name);
            $('#guarantor2_member_id').val(memberId);
        });

        // Clear member ID when selection is cleared
        $('.guarantor-select').on('select2:clear', function(e) {
            const index = $(this).attr('id').charAt(9);
            $(`#guarantor${index}_name`).val('');
            $(`#guarantor${index}_member_id`).val('');
        });

        // Validate guarantors are different
        $('.guarantor-select').on('select2:select', function(e) {
            const guarantor1Id = $('#guarantor1_member_id').val();
            const guarantor2Id = $('#guarantor2_member_id').val();

            if (guarantor1Id && guarantor2Id && guarantor1Id === guarantor2Id) {
                showAlert('Guarantors must be different members');
                $(this).val(null).trigger('change');
                const index = $(this).attr('id').charAt(9);
                $(`#guarantor${index}_name`).val('');
                $(`#guarantor${index}_member_id`).val('');
            }
        });

        // Form validation
        $('form').on('submit', function(e) {
            const terms = document.getElementById('terms');
            if (!terms.checked) {
                e.preventDefault();
                showAlert('Please accept the Terms & Conditions');
            }

            // Validate guarantor selections
            const guarantor1 = $('#guarantor1_name_select').val();
            const guarantor2 = $('#guarantor2_name_select').val();
            
            if (!guarantor1 || !guarantor2) {
                e.preventDefault();
                showAlert('Please select both guarantors');
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
        $('#amount').on('input', function() {
            const amount = parseFloat($(this).val());
            if (amount <= 0) {
                showAlert('Amount must be greater than 0');
            }
        });

        // Prevent negative values in guaranteed count
        $('.guarantor-section input[type="number"]').on('input', function() {
            if (parseInt($(this).val()) < 0) {
                $(this).val(0);
                showAlert('Guaranteed count cannot be negative');
            }
        });
    });
    </script>
</body>
</html>