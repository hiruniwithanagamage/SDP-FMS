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

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .data-table th {
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            color: white;
            padding: 12px;
            text-align: left;
            border-bottom: 2px solid #ddd;
            font-weight: 600;
        }

        /* Pagination styles */
        .pagination {
            display: flex;
            justify-content: center;
            margin: 20px 0;
            flex-wrap: wrap;
        }
        
        .pagination-info {
            text-align: center;
            margin-bottom: 10px;
            color: #555;
            font-size: 0.9rem;
            width: 100%;
        }
        
        .pagination button {
            padding: 8px 16px;
            margin: 0 4px;
            background-color: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .pagination button:hover {
            background-color: #e0e0e0;
        }
        
        .pagination button.active {
            background-color: #4a6eb5;
            color: white;
            border-color: #4a6eb5;
        }
        
        .pagination button.disabled {
            color: #aaa;
            cursor: not-allowed;
        }
        
        .pagination button.disabled:hover {
            background-color: #f8f8f8;
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
                                    <span class="status-badge <?php echo ($loanDues >= 0) ? 'status-badge-warning' : 'status-badge-success'; ?>">
                                        <?php echo ($loanDues > 0) ? 'Rs.' . number_format($loanDues, 2) : 'No Active Loans'; ?>
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
                    <div class="pdf-section-headers" style="display: none;">
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
                            <?php if (empty($paginatedPaymentHistory)): ?>
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
                                            <?php foreach ($paginatedPaymentHistory as $payment): ?>
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
                                
                                <!-- Pagination for Payment History -->
                                <?php if ($totalPaymentPages > 0): ?>
                                <div class="pagination">
                                    <div class="pagination-info">
                                        Showing <?php echo ($currentPage-1)*$recordsPerPage+1; ?> to 
                                        <?php echo min($currentPage*$recordsPerPage, $totalPaymentRecords); ?> of 
                                        <?php echo $totalPaymentRecords; ?> records
                                    </div>
                                    
                                    <!-- First and Previous buttons -->
                                    <button onclick="goToPage(1, 'payment')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-left"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $currentPage-1; ?>, 'payment')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-left"></i>
                                    </button>
                                    
                                    <!-- Page numbers -->
                                    <?php
                                    // Calculate range of page numbers to show
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalPaymentPages, $currentPage + 2);
                                    
                                    // Ensure we always show at least 5 pages when possible
                                    if ($endPage - $startPage + 1 < 5 && $totalPaymentPages >= 5) {
                                        if ($startPage == 1) {
                                            $endPage = min(5, $totalPaymentPages);
                                        } elseif ($endPage == $totalPaymentPages) {
                                            $startPage = max(1, $totalPaymentPages - 4);
                                        }
                                    }
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <button onclick="goToPage(<?php echo $i; ?>, 'payment')" 
                                                class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </button>
                                    <?php endfor; ?>
                                    
                                    <!-- Next and Last buttons -->
                                    <button onclick="goToPage(<?php echo $currentPage+1; ?>, 'payment')" 
                                            <?php echo $currentPage == $totalPaymentPages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalPaymentPages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-right"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $totalPaymentPages; ?>, 'payment')" 
                                            <?php echo $currentPage == $totalPaymentPages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalPaymentPages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-right"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div id="loan-tab" class="tab-content">
                            <h2 class="card-title">Loan History - <?php echo $selectedYear; ?></h2>
                            <?php if (empty($paginatedLoanHistory)): ?>
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
                                            <?php foreach ($paginatedLoanHistory as $loan): ?>
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
                                
                                <!-- Pagination for Loan History -->
                                <?php if ($totalLoanPages > 0): ?>
                                <div class="pagination">
                                    <div class="pagination-info">
                                        Showing <?php echo ($currentPage-1)*$recordsPerPage+1; ?> to 
                                        <?php echo min($currentPage*$recordsPerPage, $totalLoanRecords); ?> of 
                                        <?php echo $totalLoanRecords; ?> records
                                    </div>
                                    
                                    <!-- First and Previous buttons -->
                                    <button onclick="goToPage(1, 'loan')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-left"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $currentPage-1; ?>, 'loan')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-left"></i>
                                    </button>
                                    
                                    <!-- Page numbers -->
                                    <?php
                                    // Calculate range of page numbers to show
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalLoanPages, $currentPage + 2);
                                    
                                    // Ensure we always show at least 5 pages when possible
                                    if ($endPage - $startPage + 1 < 5 && $totalLoanPages >= 5) {
                                        if ($startPage == 1) {
                                            $endPage = min(5, $totalLoanPages);
                                        } elseif ($endPage == $totalLoanPages) {
                                            $startPage = max(1, $totalLoanPages - 4);
                                        }
                                    }
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <button onclick="goToPage(<?php echo $i; ?>, 'loan')" 
                                                class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </button>
                                    <?php endfor; ?>
                                    
                                    <!-- Next and Last buttons -->
                                    <button onclick="goToPage(<?php echo $currentPage+1; ?>, 'loan')" 
                                            <?php echo $currentPage == $totalLoanPages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalLoanPages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-right"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $totalLoanPages; ?>, 'loan')" 
                                            <?php echo $currentPage == $totalLoanPages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalLoanPages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-right"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div id="membership-tab" class="tab-content">
                            <h2 class="card-title">Membership Fees - <?php echo $selectedYear; ?></h2>
                            <?php if (empty($paginatedMembershipHistory)): ?>
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
                                            <?php foreach ($paginatedMembershipHistory as $fee): ?>
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
                                
                                <!-- Pagination for Membership Fees -->
                                <?php if ($totalMembershipPages > 0): ?>
                                <div class="pagination">
                                    <div class="pagination-info">
                                        Showing <?php echo ($currentPage-1)*$recordsPerPage+1; ?> to 
                                        <?php echo min($currentPage*$recordsPerPage, $totalMembershipRecords); ?> of 
                                        <?php echo $totalMembershipRecords; ?> records
                                    </div>
                                    
                                    <!-- First and Previous buttons -->
                                    <button onclick="goToPage(1, 'membership')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-left"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $currentPage-1; ?>, 'membership')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-left"></i>
                                    </button>
                                    
                                    <!-- Page numbers -->
                                    <?php
                                    // Calculate range of page numbers to show
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalMembershipPages, $currentPage + 2);
                                    
                                    // Ensure we always show at least 5 pages when possible
                                    if ($endPage - $startPage + 1 < 5 && $totalMembershipPages >= 5) {
                                        if ($startPage == 1) {
                                            $endPage = min(5, $totalMembershipPages);
                                        } elseif ($endPage == $totalMembershipPages) {
                                            $startPage = max(1, $totalMembershipPages - 4);
                                        }
                                    }
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <button onclick="goToPage(<?php echo $i; ?>, 'membership')" 
                                                class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </button>
                                    <?php endfor; ?>
                                    
                                    <!-- Next and Last buttons -->
                                    <button onclick="goToPage(<?php echo $currentPage+1; ?>, 'membership')" 
                                            <?php echo $currentPage == $totalMembershipPages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalMembershipPages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-right"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $totalMembershipPages; ?>, 'membership')" 
                                            <?php echo $currentPage == $totalMembershipPages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalMembershipPages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-right"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($missingMonths > 0): ?>
                                <div class="missing-months-notice">
                                    <p><strong>Note:</strong> There <?php echo ($missingMonths == 1) ? 'is' : 'are'; ?> <?php echo $missingMonths; ?> missing month<?php echo ($missingMonths == 1) ? '' : 's'; ?> in the system for <?php echo $selectedYear; ?>.</p>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                        
                        <div id="fine-tab" class="tab-content">
                            <h2 class="card-title">Fine History - <?php echo $selectedYear; ?></h2>
                            <?php if (empty($paginatedFineHistory)): ?>
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
                                            <?php foreach ($paginatedFineHistory as $fine): ?>
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
                                
                                <!-- Pagination for Fine History -->
                                <?php if ($totalFinePages > 0): ?>
                                <div class="pagination">
                                    <div class="pagination-info">
                                        Showing <?php echo ($currentPage-1)*$recordsPerPage+1; ?> to 
                                        <?php echo min($currentPage*$recordsPerPage, $totalFineRecords); ?> of 
                                        <?php echo $totalFineRecords; ?> records
                                    </div>
                                    
                                    <!-- First and Previous buttons -->
                                    <button onclick="goToPage(1, 'fine')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-left"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $currentPage-1; ?>, 'fine')" 
                                            <?php echo $currentPage == 1 ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == 1 ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-left"></i>
                                    </button>
                                    
                                    <!-- Page numbers -->
                                    <?php
                                    // Calculate range of page numbers to show
                                    $startPage = max(1, $currentPage - 2);
                                    $endPage = min($totalFinePages, $currentPage + 2);
                                    
                                    // Ensure we always show at least 5 pages when possible
                                    if ($endPage - $startPage + 1 < 5 && $totalFinePages >= 5) {
                                        if ($startPage == 1) {
                                            $endPage = min(5, $totalFinePages);
                                        } elseif ($endPage == $totalFinePages) {
                                            $startPage = max(1, $totalFinePages - 4);
                                        }
                                    }
                                    
                                    for ($i = $startPage; $i <= $endPage; $i++): ?>
                                        <button onclick="goToPage(<?php echo $i; ?>, 'fine')" 
                                                class="<?php echo $i == $currentPage ? 'active' : ''; ?>">
                                            <?php echo $i; ?>
                                        </button>
                                    <?php endfor; ?>
                                    
                                    <!-- Next and Last buttons -->
                                    <button onclick="goToPage(<?php echo $currentPage+1; ?>, 'fine')" 
                                            <?php echo $currentPage == $totalFinePages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalFinePages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-right"></i>
                                    </button>
                                    <button onclick="goToPage(<?php echo $totalFinePages; ?>, 'fine')" 
                                            <?php echo $currentPage == $totalFinePages ? 'class="disabled"' : ''; ?> 
                                            <?php echo $currentPage == $totalFinePages ? 'disabled' : ''; ?>>
                                        <i class="fas fa-angle-double-right"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="action-buttons">
                        <a href="../../reports/memberFSPdf.php?id=<?php echo $formattedMemberData['id']; ?>" class="action-button print-button">
                            <i class="fas fa-print"></i>/
                            <i class="fas fa-download"></i> Get Summary
                        </a>
                        
                        <a href="home-member.php" class="back-link">
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
    <script>
        /**
 * JavaScript functionality for Member Summary page
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize alerts if alertHandler.js is available
    if (typeof initAlerts === 'function') {
        initAlerts();
    } else {
        // Fallback alert handler
        const alertElements = document.querySelectorAll('.alert');
        alertElements.forEach(function(alert) {
            setTimeout(function() {
                alert.style.transition = 'opacity 0.5s ease';
                alert.style.opacity = '0';
                
                setTimeout(function() {
                    alert.remove();
                }, 500);
            }, 4000);
        });
    }
    
    // Tab switching functionality
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    // Hide all tabs except active one on initial load
    tabContents.forEach(content => {
        if (!content.classList.contains('active')) {
            content.style.display = 'none';
        } else {
            content.style.display = 'block';
        }
    });
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => {
                content.classList.remove('active');
                content.style.display = 'none';
            });
            
            // Add active class to clicked button and corresponding content
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            const activeTab = document.getElementById(tabId + '-tab');
            activeTab.classList.add('active');
            activeTab.style.display = 'block';
            
            // Store active tab in session storage
            sessionStorage.setItem('activeTab', tabId);
        });
    });
    
    // Restore active tab from sessionStorage
    const activeTab = sessionStorage.getItem('activeTab');
    if (activeTab) {
        const tabButton = document.querySelector(`.tab-button[data-tab="${activeTab}"]`);
        if (tabButton) {
            tabButton.click();
        }
    }
});

/**
 * Change year filter
 */
function changeYear(year) {
    // Store current active tab
    const activeTabButton = document.querySelector('.tab-button.active');
    const activeTab = activeTabButton ? activeTabButton.getAttribute('data-tab') : 'payment';
    sessionStorage.setItem('activeTab', activeTab);
    
    window.location.href = 'memberSummary.php?year=' + year + '&page=1';
}

/**
 * Navigate to specific page
 */
function goToPage(page, tabType) {
    // Get current year filter
    const year = document.getElementById('year-select').value;
    
    // Get current tab 
    const activeTabButton = document.querySelector('.tab-button.active');
    const activeTab = activeTabButton ? activeTabButton.getAttribute('data-tab') : 'payment';
    
    // If tabType is provided and different from current tab, use it instead
    const tabToUse = tabType || activeTab;
    
    // Store the active tab in sessionStorage for restoration after page load
    sessionStorage.setItem('activeTab', tabToUse);
    
    // Navigate to the page
    window.location.href = `memberSummary.php?year=${year}&page=${page}`;
}

/**
 * Print summary
 */
function printSummary() {
    // Add a loading indicator
    const loadingIndicator = document.createElement('div');
    loadingIndicator.innerHTML = '<div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.8); display: flex; justify-content: center; align-items: center; z-index: 9999;"><div style="background: white; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1);"><i class="fas fa-spinner fa-spin" style="margin-right: 10px;"></i> Preparing to print...</div></div>';
    document.body.appendChild(loadingIndicator);
    
    // Before printing, make all tab content visible
    const tabContents = document.querySelectorAll('.tab-content');
    tabContents.forEach(content => {
        content.setAttribute('data-original-display', content.style.display);
        content.style.display = 'block';
    });
    
    // Show PDF-specific headers
    document.querySelector('.pdf-section-headers').style.display = 'block';
    
    // Add page break classes
    document.querySelectorAll('.summary-grid').forEach((el, index) => {
        if (index < document.querySelectorAll('.summary-grid').length - 1) {
            el.classList.add('page-break-after');
        }
    });
    
    // Allow time for the DOM to update
    setTimeout(() => {
        // Remove loading indicator
        document.body.removeChild(loadingIndicator);
        
        // Print the document
        window.print();
        
        // After printing, restore original display settings
        tabContents.forEach(content => {
            const originalDisplay = content.getAttribute('data-original-display');
            content.style.display = originalDisplay || '';
            content.removeAttribute('data-original-display');
        });
        
        // Hide PDF-specific headers
        document.querySelector('.pdf-section-headers').style.display = 'none';
        
        // Remove page break classes
        document.querySelectorAll('.page-break-after').forEach(el => {
            el.classList.remove('page-break-after');
        });
        
        // Restore the active tab
        const activeTab = document.querySelector('.tab-button.active');
        if (activeTab) {
            activeTab.click();
        }
    }, 500);
}
    </script>
</body>
</html>