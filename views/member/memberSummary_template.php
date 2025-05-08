<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Financial Summary</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/alert.css">
    <link rel="stylesheet" href="../../assets/css/memberSummary.css">
    <script src="../../assets/js/alertHandler.js"></script>
    <style>
        .page-header {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 2rem;
            border-radius: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            /* margin-top: 20px; */
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .page-title {
            color: #ffffff;
            margin: 0;
            font-size: 2rem;
        }
        .filter-select {
            padding: 0.5rem 1rem;
            border: 2px solid rgba(255, 255, 255, 0.2);
            background: rgba(255, 255, 255, 0.15);
            color: white;
            border-radius: 50px;
            cursor: pointer;
        }
        .filter-select option {
            background: #1e3c72;
            color: white;
        }
</style>
</head>
<body>
    <div id="print-content" class="pdf-document">
        <div class="home-container">
            <div class="navbar-member">
                <?php include '../templates/navbar-member.php'; ?>
            </div>
            
            <div class="container">
                <div class="page-header">
                    <h1 class="page-title">Member Financial Summary</h1>
                    
                    <div class="filter">
                        <!-- <label for="year-select">Select Year:</label> -->
                        <select class="filter-select" id="year-select" onchange="changeYear(this.value)">
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?php echo $year; ?>" <?php echo ($year == $selectedYear) ? 'selected' : ''; ?>>
                                    Year <?php echo $year; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success">
                        <?php 
                        echo htmlspecialchars($_SESSION['success_message']);
                        unset($_SESSION['success_message']);
                        ?>
                    </div>
                <?php endif; ?>
                
                <?php if(isset($error)): ?>
                    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <?php if ($memberData): ?>
                    <div class="summary-grid">
                        <div class="summary-card">
                            <h2 class="card-title">
                                <i class="fas fa-user"></i> Member Information
                            </h2>
                            <div class="info-grid">
                                <div class="info-label">Name:</div>
                                <div class="info-value"><?php echo $formattedMemberData['name']; ?></div>
                                
                                <div class="info-label">Member ID:</div>
                                <div class="info-value"><?php echo $formattedMemberData['id']; ?></div>
                                
                                <div class="info-label">NIC:</div>
                                <div class="info-value"><?php echo $formattedMemberData['nic']; ?></div>
                                
                                <div class="info-label">Mobile:</div>
                                <div class="info-value"><?php echo $formattedMemberData['mobile']; ?></div>
                                
                                <div class="info-label">Joined Date:</div>
                                <div class="info-value"><?php echo date('Y-m-d', strtotime($formattedMemberData['joined_date'])); ?></div>
                            </div>
                        </div>
                        
                        <div class="summary-card">
                            <h2 class="card-title">
                                <i class="fas fa-chart-pie"></i> Financial Overview
                            </h2>
                            <div class="info-grid">
                                <div class="info-label">Status:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($formattedMemberData['status'] === 'Full Member') ? 'status-badge-success' : 'status-badge-warning'; ?>">
                                        <?php echo $formattedMemberData['status']; ?>
                                    </span>
                                </div>
                                
                                <div class="info-label">Loan Balance:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($loanDues > 0) ? 'status-badge-warning' : 'status-badge-success'; ?>">
                                        Rs.<?php echo number_format($loanDues, 2); ?>
                                    </span>
                                </div>
                                
                                <div class="info-label">Registration Fee:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($registrationDue > 0) ? 'status-badge-warning' : 'status-badge-success'; ?>">
                                        <?php echo ($registrationDue > 0) ? 'Rs.' . number_format($registrationDue, 2) . ' Due' : 'Fully Paid'; ?>
                                    </span>
                                </div>
                                
                                <div class="info-label">Membership Fees (<?php echo $selectedYear; ?>):</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($isMembershipUpToDate) ? 'status-badge-success' : 'status-badge-warning'; ?>">
                                        <?php 
                                        if ($expectedMonths == 0) {
                                            echo 'N/A';
                                        } else if ($isMembershipUpToDate) {
                                            echo 'Up to date';
                                        } else {
                                            echo 'Due';
                                        }
                                        ?>
                                    </span>
                                    <?php if (!$isMembershipUpToDate && $expectedMonths > 0): ?>
                                        <span class="months-count">
                                            (<?php echo $paidMonths; ?>/<?php echo $expectedMonths; ?> months)
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="info-label">Unpaid Fines:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($unpaidFines > 0) ? 'status-badge-danger' : 'status-badge-success'; ?>">
                                        <?php echo ($unpaidFines > 0) ? 'Rs.' . number_format($unpaidFines, 2) : 'None'; ?>
                                    </span>
                                </div>
                                
                                <div class="info-label">Total Outstanding:</div>
                                <div class="info-value">
                                    <span class="status-badge <?php echo ($totalDues > 0) ? 'status-badge-danger' : 'status-badge-success'; ?>">
                                        Rs.<?php echo number_format($totalDues, 2); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- PDF section headers that are only visible when printing -->
                    <div class="pdf-section-headers">
                        <h2 id="pdf-payment-header">Payment History - <?php echo $selectedYear; ?></h2>
                        <h2 id="pdf-loan-header">Loan History - <?php echo $selectedYear; ?></h2>
                        <h2 id="pdf-membership-header">Membership Fees - <?php echo $selectedYear; ?></h2>
                        <h2 id="pdf-fine-header">Fine History - <?php echo $selectedYear; ?></h2>
                    </div>
                    
                    <div class="tab-container">
                        <div class="tab-buttons">
                            <button class="tab-button active" data-tab="payment">Payment History</button>
                            <button class="tab-button" data-tab="loan">Loan History</button>
                            <button class="tab-button" data-tab="membership">Membership Fees</button>
                            <button class="tab-button" data-tab="fine">Fine History</button>
                        </div>
                        
                        <div id="payment-tab" class="tab-content active">
                            <h2 class="card-title">Payment History - <?php echo $selectedYear; ?></h2>
                            <?php if (empty($paymentHistory)): ?>
                                <p>No payment history found for <?php echo $selectedYear; ?>.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Payment ID</th>
                                                <th>Type</th>
                                                <th>Method</th>
                                                <th>Amount</th>
                                                <th>Date</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($paymentHistory as $payment): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($payment['PaymentID']); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['Payment_Type']); ?></td>
                                                    <td><?php echo htmlspecialchars($payment['Method']); ?></td>
                                                    <td>Rs.<?php echo number_format($payment['Amount'], 2); ?></td>
                                                    <td><?php echo date('Y-m-d', strtotime($payment['Date'])); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div id="loan-tab" class="tab-content">
                            <h2 class="card-title">Loan History - <?php echo $selectedYear; ?></h2>
                            <?php if (empty($loanHistory)): ?>
                                <p>No loan history found for <?php echo $selectedYear; ?>.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Loan ID</th>
                                                <th>Amount</th>
                                                <th>Issue Date</th>
                                                <th>Due Date</th>
                                                <th>Paid</th>
                                                <th>Remaining</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($loanHistory as $loan): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($loan['LoanID']); ?></td>
                                                    <td>Rs.<?php echo number_format($loan['Amount'], 2); ?></td>
                                                    <td><?php echo date('Y-m-d', strtotime($loan['Issued_Date'])); ?></td>
                                                    <td><?php echo date('Y-m-d', strtotime($loan['Due_Date'])); ?></td>
                                                    <td>Rs.<?php echo number_format($loan['Paid_Loan'] + $loan['Paid_Interest'], 2); ?></td>
                                                    <td>Rs.<?php echo number_format($loan['Remain_Loan'] + $loan['Remain_Interest'], 2); ?></td>
                                                    <td>
                                                        <span class="status-badge 
                                                            <?php echo ($loan['Status'] === 'approved') ? 'status-badge-success' : 
                                                                (($loan['Status'] === 'pending') ? 'status-badge-info' : 'status-badge-danger'); ?>">
                                                            <?php echo ucfirst(htmlspecialchars($loan['Status'])); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div id="membership-tab" class="tab-content">
                            <h2 class="card-title">Membership Fees - <?php echo $selectedYear; ?></h2>
                            <?php if (empty($membershipHistory)): ?>
                                <p>No membership fee history found for <?php echo $selectedYear; ?>.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Fee ID</th>
                                                <th>Type</th>
                                                <th>Date</th>
                                                <th>Term</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($membershipHistory as $fee): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($fee['FeeID']); ?></td>
                                                    <td><?php echo htmlspecialchars($fee['Type']); ?></td>
                                                    <td><?php echo date('Y-m-d', strtotime($fee['Date'])); ?></td>
                                                    <td><?php echo htmlspecialchars($fee['Term']); ?></td>
                                                    <td>Rs.<?php echo number_format($fee['Amount'], 2); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo ($fee['IsPaid'] === 'Yes') ? 'status-badge-success' : 'status-badge-warning'; ?>">
                                                            <?php echo ($fee['IsPaid'] === 'Yes') ? 'Paid' : 'Unpaid'; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                
                                <?php if ($missingMonths > 0): ?>
                                <div class="missing-months-notice">
                                    <p><strong>Note:</strong> There <?php echo ($missingMonths == 1) ? 'is' : 'are'; ?> <?php echo $missingMonths; ?> missing month<?php echo ($missingMonths == 1) ? '' : 's'; ?> in the system for <?php echo $selectedYear; ?>.</p>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div id="fine-tab" class="tab-content">
                            <h2 class="card-title">Fine History - <?php echo $selectedYear; ?></h2>
                            <?php if (empty($fineHistory)): ?>
                                <p>No fine history found for <?php echo $selectedYear; ?>.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Fine ID</th>
                                                <th>Type</th>
                                                <th>Date</th>
                                                <th>Amount</th>
                                                <th>Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($fineHistory as $fine): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($fine['FineID']); ?></td>
                                                    <td><?php echo htmlspecialchars($fine['Description']); ?></td>
                                                    <td><?php echo date('Y-m-d', strtotime($fine['Date'])); ?></td>
                                                    <td>Rs.<?php echo number_format($fine['Amount'], 2); ?></td>
                                                    <td>
                                                        <span class="status-badge <?php echo ($fine['IsPaid'] === 'Yes') ? 'status-badge-success' : 'status-badge-warning'; ?>">
                                                            <?php echo ($fine['IsPaid'] === 'Yes') ? 'Paid' : 'Unpaid'; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="../../reports/memberFSPdf.php?id=<?php echo $formattedMemberData['id']; ?>" class="action-button print-button">
                            <i class="fas fa-print"></i>/
                            <i class="fas fa-download"></i> Get Summary
                        </a>
                        
                        <!-- <a href="../../reports/memberFSPdf.php?id=<?php echo $formattedMemberData['id']; ?>&year=<?php echo $selectedYear; ?>&download=true" class="action-button download-button">
                            <i class="fas fa-download"></i> Download PDF
                        </a> -->
                        
                        <a href="index.php" class="back-link">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="footer">
                <?php include '../templates/footer.php'; ?>
            </div>
        </div>
    </div>
    
    <!-- PDF generation library - include CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="../../assets/js/memberSummary.js"></script>
</body>
</html>