<?php
session_start();
require_once "../../config/database.php";

// Verify treasurer authentication
if (!isset($_SESSION['treasurer_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get current date and year
$currentDate = date('Y-m-d');
$currentYear = date('Y');

// Fetch static data for current year
$query = "SELECT * FROM Static WHERE year = $currentYear";
$result = Database::search($query);
if ($result->num_rows === 0) {
    $query = "SELECT * FROM Static ORDER BY year DESC LIMIT 1";
    $result = Database::search($query);
}
$staticData = $result->fetch_assoc();

// Get treasurer details
$treasurerId = $_SESSION['treasurer_id'];
$query = "SELECT Name FROM Treasurer WHERE TreasurerID = '$treasurerId'";
$result = Database::search($query);
$treasurerData = $result->fetch_assoc();

// Fetch all eligible members for guarantor selection
$memberQuery = "SELECT MemberID, Name FROM Member";
$memberQueryResult = Database::search($memberQuery);
$memberList = [];

if ($memberQueryResult && $memberQueryResult->num_rows > 0) {
    while ($row = $memberQueryResult->fetch_assoc()) {
        $memberList[] = [
            'id' => $row['MemberID'],
            'text' => $row['Name']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Treasurer Payment</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/memberPayment.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <style>
        .member-search {
            margin-bottom: 2rem;
        }
        .member-search select {
            width: 100%;
            padding: 0.8rem;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
            font-size: 1rem;
        }
        .member-info {
            background: #f8fafc;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            border: 1px solid #e2e8f0;
        }
        .select2-container {
            width: 100% !important;
        }
        .select2-container .select2-selection--single {
            height: 38px;
            border: 2px solid #e0e0e0;
            border-radius: 4px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 36px;
            padding-left: 12px;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 36px;
        }
        .select2-dropdown {
            border: 2px solid #e0e0e0;
        }
        .select2-search__field {
            border: 1px solid #e0e0e0 !important;
            padding: 4px 8px !important;
        }
        .select2-results__option {
            padding: 8px 12px;
        }
    </style>
</head>
<body>
    <div class="main-container">
        <?php include '../templates/navbar-treasurer.php'; ?> 
        <div class="container">
            <h1>Process Member Payment</h1>
            
            <?php if(isset($_SESSION['error_message'])): ?>
                <div class="alert alert-error">
                    <?php 
                        echo $_SESSION['error_message'];
                        unset($_SESSION['error_message']);
                    ?>
                </div>
            <?php endif; ?>

            <?php if(isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success">
                    <?php 
                        echo $_SESSION['success_message'];
                        unset($_SESSION['success_message']);
                    ?>
                </div>
            <?php endif; ?>

            <form id="paymentForm" action="process_treasurer_payment.php" method="POST">
                <!-- Member Selection -->
                <div class="form-group member-search">
                    <label>Select Member</label>
                    <select id="member_select" class="member-select" required>
                        <option value="">Select or search for a member...</option>
                        <?php foreach ($memberList as $memberItem): ?>
                            <option value="<?php echo $memberItem['id']; ?>"><?php echo $memberItem['text']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Member Info Display -->
                <div class="member-info" style="display: none;">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Name</label>
                            <input type="text" id="memberName" disabled>
                        </div>
                        <div class="form-group">
                            <label>Member ID</label>
                            <input type="text" id="memberId" disabled>
                            <input type="hidden" name="member_id" id="member_id_hidden">
                        </div>
                    </div>
                </div>

                <!-- Date and Year -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="date" value="<?php echo $currentDate; ?>" readonly>
                    </div>
                    <div class="form-group">
                        <label>Year</label>
                        <select name="year" id="yearSelect">
                            <?php
                            $yearQuery = "SELECT DISTINCT year FROM Static ORDER BY year DESC";
                            $yearResult = Database::search($yearQuery);
                            while($yearRow = $yearResult->fetch_assoc()): ?>
                                <option value="<?php echo $yearRow['year']; ?>" 
                                    <?php echo ($yearRow['year'] == $currentYear) ? 'selected' : ''; ?>>
                                    <?php echo $yearRow['year']; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                </div>

                <!-- Payment Type Selection -->
                <div class="form-row">
                    <div class="form-group">
                        <label>Type</label>
                        <select name="payment_type" id="paymentType" required>
                            <option value="">Select payment type</option>
                            <option value="registration">Registration Fee</option>
                            <option value="monthly">Monthly Fee</option>
                            <option value="loan">Loan Payment</option>
                            <option value="fine">Fine Payment</option>
                        </select>
                    </div>
                </div>

                <!-- Dynamic Sections -->
                <div id="registrationFeeContainer" style="display: none;"></div>
                <div id="monthSelectionContainer" style="display: none;">
                    <label>Select Months</label>
                    <div class="months-grid">
                        <?php
                        $months = ['January', 'February', 'March', 'April', 'May', 'June', 
                                 'July', 'August', 'September', 'October', 'November', 'December'];
                        foreach ($months as $index => $month) {
                            $monthNum = $index + 1;
                            echo "<label class='month-checkbox'>
                                    <input type='checkbox' name='selected_months[]' value='" . $monthNum . "'>
                                    $month
                                  </label>";
                        }
                        ?>
                    </div>
                </div>
                <div id="fineTypeContainer" style="display: none;"></div>
                <div id="loanDetailsContainer" style="display: none;"></div>

                <!-- Amount -->
                <div class="form-group">
                    <label>Amount</label>
                    <input type="number" name="amount" id="amount" step="0.01" readonly>
                    <small class="amount-hint" id="amountHint"></small>
                </div>

                <!-- Payment Method -->
                <div class="payment-method-section">
                    <label>Payment Method</label>
                    <div class="payment-methods">
                        <label>
                            <input type="radio" name="payment_method" value="onhand" checked>
                            On Hand Payment
                        </label>
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="button-group">
                    <button type="submit" class="btn-submit">Process Payment</button>
                    <button type="button" class="btn-cancel" onclick="window.location.href='dashboard.php'">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    // Pass PHP data to JavaScript
    const staticData = <?php echo json_encode($staticData); ?>;
    const remainingRegFee = <?php echo $remainingRegFee; ?>;
    const memberStatus = "<?php echo $memberStatus; ?>";

    $(document).ready(function() {
        // Initialize Select2
        $('#member_select').select2({
            placeholder: 'Select or search for a member...',
            allowClear: true,
            width: '100%',
            dropdownParent: $('body'),
            matcher: function(params, data) {
                if ($.trim(params.term) === '') {
                    return data;
                }

                if (typeof data.text === 'undefined') {
                    return null;
                }

                // Search in both name and ID
                if (data.text.toLowerCase().indexOf(params.term.toLowerCase()) > -1 || 
                    data.id.toLowerCase().indexOf(params.term.toLowerCase()) > -1) {
                    return data;
                }

                return null;
            }
        }).on('select2:select', function(e) {
            const data = e.params.data;
            const name = data.text.split(' (')[0];
            const memberId = data.id;
            
            $('#memberName').val(name);
            $('#memberId').val(memberId);
            $('#member_id_hidden').val(memberId);
            $('.member-info').show();

            // Fetch member details for payment options
            fetchMemberDetails(memberId);
        }).on('select2:clear', function() {
            $('.member-info').hide();
            $('#memberName').val('');
            $('#memberId').val('');
            $('#member_id_hidden').val('');
            resetForm();
        });
    });
    </script>
    <script src="../../assets/js/memberPayment.js"></script>
</body>
</html>