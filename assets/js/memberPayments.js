// Clean up the memberPayment.js file by removing duplicated code and consolidating event listeners
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('paymentForm');
    const yearSelect = document.getElementById('yearSelect');
    const paymentType = document.getElementById('paymentType');
    const fineTypeContainer = document.getElementById('fineTypeContainer');
    const fineSelect = document.getElementById('fineSelect');
    const monthSelectionContainer = document.getElementById('monthSelectionContainer');
    const amountInput = document.getElementById('amount');
    const amountHint = document.getElementById('amountHint');
    const cardDetails = document.getElementById('cardDetails');
    const bankTransfer = document.getElementById('bankTransfer');
    const monthCheckboxes = document.querySelectorAll('input[name="selected_months[]"]');
    const loanDetailsContainer = document.getElementById('loanDetailsContainer');
    const loanSelect = document.getElementById('loanSelect');
    
    // Store loan details for calculations
    let currentLoanDetails = {
        principal: 0,
        interest: 0
    };

    // Card number formatting - uncomment and fix this section
    const cardNumberInput = document.querySelector('input[name="card_number"]');
    if (cardNumberInput) {
        cardNumberInput.addEventListener('input', function(e) {
            // Remove all non-digit characters
            let value = this.value.replace(/\D/g, '');
            
            // Add spaces after every 4 digits
            value = value.replace(/(\d{4})(?=\d)/g, '$1 ');
            
            // Update the input value
            this.value = value;
        });
    }

    // Expire date formatting - use this consolidated version
    const expireDate = document.querySelector('input[name="expire_date"]');
    if (expireDate) {
        expireDate.addEventListener('input', function(e) {
            let value = this.value.replace(/\D/g, '');
            
            if (value.length > 2) {
                value = value.substring(0, 2) + '/' + value.substring(2, 4);
            }
            
            this.value = value;
            
            // Validate month (01-12)
            if (value.length >= 2) {
                const month = parseInt(value.substring(0, 2));
                if (month < 1 || month > 12) {
                    this.setCustomValidity('Please enter a valid month (01-12)');
                } else {
                    this.setCustomValidity('');
                }
            }
        });
    }

    // Format CVV to allow only 3 digits
    const cvvInput = document.querySelector('input[name="cvv"]');
    if (cvvInput) {
        cvvInput.addEventListener('input', function() {
            this.value = this.value.replace(/\D/g, '').substring(0, 3);
        });
    }

    // Year selection handler
    // yearSelect.addEventListener('change', function() {
    //     fetchAndUpdateFees(this.value);
    // });

    // Payment type change handler
    paymentType.addEventListener('change', function() {
        resetForm();
        
        switch(this.value) {
            case 'registration':
                document.getElementById('registrationFeeContainer').style.display = 'block';
                amountInput.value = remainingRegFee;
                amountInput.max = remainingRegFee;
                amountInput.min = 1;
                amountInput.readOnly = false; // Make it editable
                amountHint.textContent = 'You can pay any amount up to the remaining balance';
                break;
                
            case 'monthly':
                monthSelectionContainer.style.display = 'block';
                calculateMonthlyFee();
                amountInput.readOnly = true;
                amountHint.textContent = `Rs. ${staticData.monthly_fee} per month`;
                break;
                
            case 'fine':
                fineTypeContainer.style.display = 'block';
                updateFineAmount();
                break;
                
            case 'loan':
                if (loanDetailsContainer) {
                    loanDetailsContainer.style.display = 'block';
                }
                amountInput.readOnly = false; // Make amount editable for loans
                amountInput.value = ''; // Clear the amount field
                amountHint.textContent = 'Enter loan payment amount';
                break;
                
            default:
                amountInput.value = '';
                amountInput.removeAttribute('max');
                amountInput.removeAttribute('min');
                amountHint.textContent = '';
        }
    });

    // Add amount validation
    amountInput.addEventListener('input', function() {
        if (paymentType.value === 'registration') {
            const maxAmount = parseFloat(this.getAttribute('max'));
            const value = parseFloat(this.value);
            
            if (value > maxAmount) {
                this.value = maxAmount;
            } else if (value < 1) {
                this.value = 1;
            }
        } else if (paymentType.value === 'loan') {
            // Validate loan payment amount
            const value = parseFloat(this.value) || 0;
            const maxAmount = parseFloat(this.getAttribute('max')) || 0;
            
            if (value > maxAmount) {
                this.value = maxAmount;
            } else if (value < 0) {
                this.value = 0;
            }
            
            // Update payment breakdown
            updateLoanPaymentBreakdown(value);
        }
    });

    // Registration amount validation
    const regAmount = document.getElementById('regAmount');
    if (regAmount) {
        regAmount.addEventListener('input', function() {
            const max = parseFloat(this.getAttribute('max'));
            const value = parseFloat(this.value);
            
            if (value > max) {
                this.value = max;
            } else if (value < 1) {
                this.value = 1;
            }
        });
    }

    // Fine selection handler
    if (fineSelect) {
        fineSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                amountInput.value = selectedOption.dataset.amount;
                amountHint.textContent = 'Fixed fine amount';
            } else {
                amountInput.value = '';
                amountHint.textContent = '';
            }
        });
    }

    // Month selection handler
    monthCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', calculateMonthlyFee);
    });

    // Loan selection handler
    if (loanSelect) {
        loanSelect.addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            if (selectedOption.value) {
                const principal = parseFloat(selectedOption.dataset.principal);
                const interest = parseFloat(selectedOption.dataset.interest);
                const total = principal + interest;
                
                // Store the current loan details
                currentLoanDetails.principal = principal;
                currentLoanDetails.interest = interest;
                
                amountInput.setAttribute('max', total);
                amountInput.min = 0;
                amountInput.value = total.toFixed(2); // Set default to total
                
                // Update payment breakdown
                updateLoanPaymentBreakdown(total);
            } else {
                amountInput.removeAttribute('max');
                amountInput.value = '';
                amountHint.textContent = '';
                
                // Reset loan details
                currentLoanDetails.principal = 0;
                currentLoanDetails.interest = 0;
            }
        });
    }

    // Function to update loan payment breakdown display
    function updateLoanPaymentBreakdown(paymentAmount) {
        if (!paymentAmount || paymentAmount <= 0) {
            amountHint.innerHTML = 'Enter a payment amount';
            return;
        }
        
        const { interest, principal } = currentLoanDetails;
        const total = interest + principal;
        
        if (total <= 0) {
            amountHint.innerHTML = 'No loan balance to pay';
            return;
        }
        
        // Calculate how payment will be allocated (interest first)
        const interestPayment = Math.min(paymentAmount, interest);
        const principalPayment = Math.max(0, paymentAmount - interestPayment);
        
        // Calculate remaining balances
        const remainingInterest = Math.max(0, interest - interestPayment);
        const remainingPrincipal = Math.max(0, principal - principalPayment);
        
        // Build the breakdown message
        let message = `<strong>Payment Breakdown (Rs. ${paymentAmount.toFixed(2)}):</strong><br>`;
        message += `- Interest payment: Rs. ${interestPayment.toFixed(2)}<br>`;
        
        if (principalPayment > 0) {
            message += `- Principal payment: Rs. ${principalPayment.toFixed(2)}<br>`;
        }
        
        message += `<br><strong>Remaining after payment:</strong><br>`;
        message += `- Interest: Rs. ${remainingInterest.toFixed(2)}<br>`;
        message += `- Principal: Rs. ${remainingPrincipal.toFixed(2)}<br>`;
        message += `- Total: Rs. ${(remainingInterest + remainingPrincipal).toFixed(2)}`;
        
        // Add note about interest-first allocation
        message += `<br><br><em>Note: Payments are applied to interest first, then to principal.</em>`;
        
        amountHint.innerHTML = message;
    }

    // Payment method handler
    const paymentMethodRadios = document.querySelectorAll('input[name="payment_method"]');
    paymentMethodRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.value === 'online') {
                cardDetails.style.display = 'block';
                bankTransfer.style.display = 'none';
                
                // Enable card field validation
                enableCardFieldValidation(true);
                
                // Disable bank transfer field validation
                const receiptUpload = bankTransfer.querySelector('input[name="receipt"]');
                if (receiptUpload) {
                    receiptUpload.required = false;
                }
            } else if (this.value === 'transfer') {
                cardDetails.style.display = 'none';
                bankTransfer.style.display = 'block';
                
                // Disable card field validation
                enableCardFieldValidation(false);
                
                // Enable bank transfer field validation
                const receiptUpload = bankTransfer.querySelector('input[name="receipt"]');
                if (receiptUpload) {
                    receiptUpload.required = true;
                }
            }
        });
    });
    
    // Function to enable/disable card field validation
    function enableCardFieldValidation(enable) {
        const cardFields = cardDetails.querySelectorAll('input');
        cardFields.forEach(field => {
            if (enable) {
                // Store original attributes in data attributes if not already stored
                if (!field.dataset.originalRequired) {
                    field.dataset.originalRequired = field.required || false;
                    field.dataset.originalPattern = field.pattern || '';
                }
                
                // Restore original validation attributes
                field.required = field.dataset.originalRequired === 'true';
                field.pattern = field.dataset.originalPattern;
            } else {
                // Store original attributes if not already stored
                if (!field.dataset.originalRequired) {
                    field.dataset.originalRequired = field.required || false;
                    field.dataset.originalPattern = field.pattern || '';
                }
                
                // Remove validation attributes
                field.required = false;
                field.pattern = '';
            }
        });
    }

    // Form submission handler
    form.addEventListener('submit', function(e) {
        const selectedPaymentMethod = document.querySelector('input[name="payment_method"]:checked');
        
        if (!selectedPaymentMethod) {
            e.preventDefault();
            alert('Please select a payment method');
            return;
        }
        
        if (selectedPaymentMethod.value === 'online') {
            // Validate card fields
            const cardNumber = document.querySelector('input[name="card_number"]').value;
            const expireDate = document.querySelector('input[name="expire_date"]').value;
            const cvv = document.querySelector('input[name="cvv"]').value;
            
            if (!cardNumber || !expireDate || !cvv) {
                e.preventDefault();
                alert('Please fill in all card details');
                return;
            }
        } else if (selectedPaymentMethod.value === 'transfer') {
            // Validate receipt upload
            const receiptUpload = document.querySelector('input[name="receipt"]');
            if (receiptUpload && !receiptUpload.files.length) {
                e.preventDefault();
                alert('Please upload a receipt for bank transfer');
                return;
            }
        }
        
        if (!validateForm()) {
            e.preventDefault();
            return;
        }
    });

    // Helper functions
    function resetForm() {
        // Hide all containers
        const containers = [
            fineTypeContainer, 
            monthSelectionContainer, 
            loanDetailsContainer,
            document.getElementById('registrationFeeContainer')
        ];

        containers.forEach(container => {
            if (container) {
                container.style.display = 'none';
            }
        });

        // Reset other form elements
        amountInput.readOnly = true;
        amountInput.value = '';
        amountHint.textContent = '';
        monthCheckboxes.forEach(cb => cb.checked = false);
        removeAllErrors();
        
        // Reset loan details
        currentLoanDetails.principal = 0;
        currentLoanDetails.interest = 0;

        // Reset payment methods
        document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
            radio.checked = false;
        });
        cardDetails.style.display = 'none';
        bankTransfer.style.display = 'none';
    }

    function calculateMonthlyFee() {
        const selectedMonths = document.querySelectorAll('input[name="selected_months[]"]:checked').length;
        amountInput.value = (selectedMonths * staticData.monthly_fee).toFixed(2);
        amountHint.textContent = `${selectedMonths} month(s) × Rs. ${staticData.monthly_fee}`;
    }

    // async function fetchAndUpdateFees(year) {
    //     try {
    //         const response = await fetch(`get_fee_structure.php?year=${year}`);
    //         if (!response.ok) throw new Error('Failed to fetch fee structure');
            
    //         const data = await response.json();
    //         if (data.error) {
    //             showError(yearSelect, data.error);
    //             return;
    //         }

    //         staticData = data.fee_structure;
    //         if (paymentType.value) {
    //             paymentType.dispatchEvent(new Event('change'));
    //         }

    //         if (!data.metadata.is_exact_match) {
    //             showNotification(`Using fee structure from year ${data.metadata.actual_year}`);
    //         }
    //     } catch (error) {
    //         console.error('Error:', error);
    //         showError(yearSelect, 'Failed to fetch fee structure');
    //     }
    // }

    function validateForm() {
        let isValid = true;
        removeAllErrors();

        // Validate payment type
        if (!paymentType.value) {
            showError(paymentType, 'Please select a payment type');
            isValid = false;
        }

        // Validate amount
        if (!amountInput.value || parseFloat(amountInput.value) <= 0) {
            showError(amountInput, 'Please enter a valid amount');
            isValid = false;
        }

        // Validate loan payment
        if (paymentType.value === 'loan' && loanSelect) {
            if (!loanSelect.value) {
                showError(loanSelect, 'Please select a loan');
                isValid = false;
            } else {
                const total = currentLoanDetails.principal + currentLoanDetails.interest;
                if (parseFloat(amountInput.value) > total) {
                    showError(amountInput, 'Payment amount cannot exceed total remaining amount');
                    isValid = false;
                }
            }
        }

        // Validate monthly payment selections
        if (paymentType.value === 'monthly' && 
            !document.querySelector('input[name="selected_months[]"]:checked')) {
            showError(monthSelectionContainer, 'Please select at least one month');
            isValid = false;
        }

        // Validate payment method
        const paymentMethod = document.querySelector('input[name="payment_method"]:checked');
        if (!paymentMethod) {
            showError(document.querySelector('.payment-methods'), 'Please select a payment method');
            isValid = false;
        } else {
            if (paymentMethod.value === 'online') {
                isValid = validateCardDetails() && isValid;
            } else if (paymentMethod.value === 'transfer') {
                isValid = validateBankTransfer() && isValid;
            }
        }

        return isValid;
    }

    function validateCardDetails() {
        let isValid = true;
        const cardNumber = document.querySelector('input[name="card_number"]');
        const expireDate = document.querySelector('input[name="expire_date"]');
        const cvv = document.querySelector('input[name="cvv"]');

        // Validate card number - updated regex to allow spaces
        if (!cardNumber.value.replace(/\s/g, '').match(/^\d{16}$/)) {
            showError(cardNumber, 'Please enter a valid 16-digit card number');
            isValid = false;
        }

        // Validate expiry date
        if (!expireDate.value.match(/^(0[1-9]|1[0-2])\/\d{2}$/)) {
            showError(expireDate, 'Please enter a valid expiry date (MM/YY)');
            isValid = false;
        } else {
            const [month, year] = expireDate.value.split('/');
            const expiry = new Date(2000 + parseInt(year), parseInt(month) - 1);
            const today = new Date();
            if (expiry < today) {
                showError(expireDate, 'Card has expired');
                isValid = false;
            }
        }

        // Validate CVV
        if (!cvv.value.match(/^\d{3}$/)) {
            showError(cvv, 'Please enter a valid 3-digit CVV');
            isValid = false;
        }

        return isValid;
    }

    function validateBankTransfer() {
        const receipt = document.querySelector('input[name="receipt"]');
        if (!receipt.files.length) {
            showError(receipt, 'Please upload a receipt');
            return false;
        }

        const file = receipt.files[0];
        const maxSize = 5 * 1024 * 1024; // 5MB
        const allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];

        if (file.size > maxSize) {
            showError(receipt, 'File size must be less than 5MB');
            return false;
        }

        if (!allowedTypes.includes(file.type)) {
            showError(receipt, 'Only JPG, PNG and GIF files are allowed');
            return false;
        }

        return true;
    }

    function showError(element, message) {
        const formGroup = element.closest('.form-group');
        formGroup.classList.add('error');
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'error-message';
        errorDiv.textContent = message;
        formGroup.appendChild(errorDiv);
    }

    function removeAllErrors() {
        document.querySelectorAll('.error').forEach(el => el.classList.remove('error'));
        document.querySelectorAll('.error-message').forEach(el => el.remove());
    }

    function showNotification(message) {
        const notification = document.createElement('div');
        notification.className = 'alert alert-info';
        notification.textContent = message;
        form.insertBefore(notification, form.firstChild);
        
        setTimeout(() => notification.remove(), 5000);
    }
});