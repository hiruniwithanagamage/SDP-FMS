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
    
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Remove active class from all buttons and contents
            tabButtons.forEach(btn => btn.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));
            
            // Add active class to clicked button and corresponding content
            this.classList.add('active');
            const tabId = this.getAttribute('data-tab');
            document.getElementById(tabId + '-tab').classList.add('active');
        });
    });
});

/**
 * Change year filter
 */
function changeYear(year) {
    window.location.href = 'memberSummary.php?year=' + year;
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

/**
 * Download PDF
 */
// function downloadPDF() {
//     // Show loading indicator
//     const loadingIndicator = document.createElement('div');
//     loadingIndicator.innerHTML = '<div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(255,255,255,0.8); display: flex; justify-content: center; align-items: center; z-index: 9999;"><div style="background: white; padding: 20px; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1);"><i class="fas fa-spinner fa-spin" style="margin-right: 10px;"></i> Generating PDF...</div></div>';
//     document.body.appendChild(loadingIndicator);
    
//     // Show all tab content for PDF generation
//     const tabContents = document.querySelectorAll('.tab-content');
//     tabContents.forEach(content => {
//         content.setAttribute('data-original-display', content.style.display);
//         content.style.display = 'block';
//     });
    
//     // Show PDF-specific headers
//     document.querySelector('.pdf-section-headers').style.display = 'block';
    
//     // Add PDF-specific classes
//     document.querySelectorAll('.summary-grid').forEach((el, index) => {
//         if (index < document.querySelectorAll('.summary-grid').length - 1) {
//             el.classList.add('page-break-after');
//         }
//     });
    
//     // Options for PDF generation
//     const options = {
//         margin: [10, 10, 10, 10],
//         filename: 'member_financial_summary_' + document.getElementById('year-select').value + '.pdf',
//         image: { type: 'jpeg', quality: 0.98 },
//         html2canvas: { 
//             scale: 2,
//             useCORS: true,
//             logging: false,
//             letterRendering: true
//         },
//         jsPDF: { 
//             unit: 'mm', 
//             format: 'a4', 
//             orientation: 'portrait',
//             compress: true
//         },
//         pagebreak: { mode: ['avoid-all', 'css', 'legacy'] }
//     };
    
//     // Get the content element
//     const element = document.getElementById('print-content');
    
//     // Create a clone to avoid modifying the original
//     const clonedElement = element.cloneNode(true);
    
//     // Remove elements that shouldn't be in the PDF
//     const elementsToRemove = clonedElement.querySelectorAll('.navbar-member, .action-buttons, .back-link, .footer, .year-filter, .tab-buttons');
//     elementsToRemove.forEach(el => {
//         if (el && el.parentNode) {
//             el.parentNode.removeChild(el);
//         }
//     });
    
//     // Add year to PDF title and ensure it's visible
//     const titleElement = clonedElement.querySelector('.page-title');
//     if (titleElement) {
//         titleElement.textContent = 'Member Financial Summary - Year: ' + document.getElementById('year-select').value;
//         titleElement.style.display = 'block';
//         titleElement.style.textAlign = 'center';
//         titleElement.style.marginBottom = '20px';
//     }
    
//     // Apply consistent styling to tables
//     const tables = clonedElement.querySelectorAll('table');
//     tables.forEach(table => {
//         table.style.width = '100%';
//         table.style.borderCollapse = 'collapse';
//         table.style.marginBottom = '20px';
        
//         const rows = table.querySelectorAll('tr');
//         rows.forEach(row => {
//             const cells = row.querySelectorAll('th, td');
//             cells.forEach(cell => {
//                 cell.style.border = '1px solid #ddd';
//                 cell.style.padding = '8px';
//             });
//         });
//     });
    
//     // Generate the PDF
//     html2pdf().set(options).from(clonedElement).save().then(() => {
//         // Remove loading indicator
//         document.body.removeChild(loadingIndicator);
        
//         // Restore original display settings after PDF generation
//         tabContents.forEach(content => {
//             const originalDisplay = content.getAttribute('data-original-display');
//             content.style.display = originalDisplay || '';
//             content.removeAttribute('data-original-display');
//         });
        
//         // Hide PDF-specific headers
//         document.querySelector('.pdf-section-headers').style.display = 'none';
        
//         // Remove PDF-specific classes
//         document.querySelectorAll('.page-break-after').forEach(el => {
//             el.classList.remove('page-break-after');
//         });
        
//         // Restore the active tab
//         const activeTab = document.querySelector('.tab-button.active');
//         if (activeTab) {
//             activeTab.click();
//         }
//     }).catch(error => {
//         console.error('Error generating PDF:', error);
//         alert('There was an error generating the PDF. Please try again.');
        
//         // Remove loading indicator
//         document.body.removeChild(loadingIndicator);
        
//         // Restore display settings
//         tabContents.forEach(content => {
//             const originalDisplay = content.getAttribute('data-original-display');
//             content.style.display = originalDisplay || '';
//             content.removeAttribute('data-original-display');
//         });
        
//         // Hide PDF-specific headers
//         document.querySelector('.pdf-section-headers').style.display = 'none';
        
//         // Remove PDF-specific classes
//         document.querySelectorAll('.page-break-after').forEach(el => {
//             el.classList.remove('page-break-after');
//         });
//     });
// }