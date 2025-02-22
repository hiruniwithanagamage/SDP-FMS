$(document).ready(function() {
    // Initialize Select2
    $('#member_select').select2({
        placeholder: 'Select or search for a member...',
        allowClear: true,
        width: '100%'
    }).on('change', function() {
        $('#memberSelectForm').submit();
    });

    // Payment type change handler
    $('#paymentType').on('change', function() {
        const selectedType = $(this).val();
        
        // Hide all containers first
        $('#registrationFeeContainer').hide();
        $('#monthSelectionContainer').hide();
        $('#fineTypeContainer').hide();
        $('#loanDetailsContainer').hide();
        
        // Reset amount
        $('#amount').val('');
        $('#amountHint').text('');

        // Show relevant container based on selection
        switch(selectedType) {
            case 'registration':
                $('#registrationFeeContainer').show();
                const remainingRegFee = parseFloat($('#registrationFeeContainer .fee-info p:nth-child(3)').text().replace('Remaining Amount: Rs. ', '').replace(/,/g, ''));
                $('#amount').val(remainingRegFee.toFixed(2));
                $('#amount').prop('readonly', false); // Make amount editable
                $('#amountHint').text(`Maximum amount: Rs. ${remainingRegFee.toFixed(2)}`);
                
                // Add event listener for amount validation
                $('#amount').on('input', function() {
                    const enteredAmount = parseFloat($(this).val()) || 0;
                    if (enteredAmount > remainingRegFee) {
                        alert('Amount cannot exceed the remaining registration fee');
                        $(this).val(remainingRegFee.toFixed(2));
                    } else if (enteredAmount < 0) {
                        alert('Amount cannot be negative');
                        $(this).val('0.00');
                    }
                });
                break;

            case 'monthly':
                $('#monthSelectionContainer').show();
                $('#amount').prop('readonly', true);
                updateMonthlyFeeAmount();
                break;

            case 'fine':
                $('#fineTypeContainer').show();
                $('#amount').prop('readonly', true);
                break;

            case 'loan':
                $('#loanDetailsContainer').show();
                $('#amount').prop('readonly', true);
                break;
        }
    });

    // Monthly fee calculation
    function updateMonthlyFeeAmount() {
        if (!staticData || !staticData.monthly_fee) {
            console.error('Monthly fee data not available');
            return;
        }

        const monthlyFee = parseFloat(staticData.monthly_fee);
        const selectedMonths = $('.months-grid input[type="checkbox"]:checked:not([disabled])').length;
        const totalAmount = monthlyFee * selectedMonths;

        if (selectedMonths > 0) {
            $('#amount').val(totalAmount.toFixed(2));
            $('#amountHint').text(`Monthly fee (Rs. ${monthlyFee.toFixed(2)}) × ${selectedMonths} month(s)`);
        } else {
            $('#amount').val('');
            $('#amountHint').text('');
        }
    }

    // Add event listener for month checkboxes
    $(document).on('change', '.months-grid input[type="checkbox"]', function() {
        updateMonthlyFeeAmount();
    });

    // Fine amount handling
    $('#fineSelect').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const fineAmount = parseFloat(selectedOption.data('amount'));
        if (fineAmount) {
            $('#amount').val(fineAmount.toFixed(2));
        } else {
            $('#amount').val('');
        }
    });

    // Loan payment handling
    $('#loanSelect').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const principal = parseFloat(selectedOption.data('principal')) || 0;
        const interest = parseFloat(selectedOption.data('interest')) || 0;
        const totalAmount = principal + interest;
        
        if (totalAmount > 0) {
            $('#amount').val(totalAmount.toFixed(2));
            $('#amountHint').text(`Principal: Rs. ${principal.toFixed(2)} + Interest: Rs. ${interest.toFixed(2)}`);
        } else {
            $('#amount').val('');
            $('#amountHint').text('');
        }
    });

    // Form validation
    $('#paymentForm').on('submit', function(e) {
        const amount = parseFloat($('#amount').val());
        const paymentType = $('#paymentType').val();

        if (!paymentType) {
            e.preventDefault();
            alert('Please select a payment type');
            return false;
        }

        if (!amount || amount <= 0) {
            e.preventDefault();
            alert('Please ensure a valid amount is selected');
            return false;
        }

        if (paymentType === 'registration') {
            const remainingRegFee = parseFloat($('#registrationFeeContainer .fee-info p:nth-child(3)').text().replace('Remaining Amount: Rs. ', '').replace(/,/g, ''));
            if (amount > remainingRegFee) {
                e.preventDefault();
                alert('Amount cannot exceed the remaining registration fee');
                return false;
            }
        }

        if (paymentType === 'monthly' && $('.months-grid input[type="checkbox"]:checked:not([disabled])').length === 0) {
            e.preventDefault();
            alert('Please select at least one month for monthly fee payment');
            return false;
        }

        if (paymentType === 'fine' && !$('#fineSelect').val()) {
            e.preventDefault();
            alert('Please select a fine to pay');
            return false;
        }

        if (paymentType === 'loan' && !$('#loanSelect').val()) {
            e.preventDefault();
            alert('Please select a loan to pay');
            return false;
        }
    });
});