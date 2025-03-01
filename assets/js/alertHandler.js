// File: assets/js/alert_handler.js
document.addEventListener('DOMContentLoaded', function() {
    // Add close functionality to alerts
    const alerts = document.querySelectorAll('.alert');
    
    alerts.forEach(function(alert) {
        // Add close button to each alert
        const closeBtn = document.createElement('span');
        closeBtn.innerHTML = '&times;';
        closeBtn.className = 'alert-close';
        closeBtn.style.float = 'right';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.fontWeight = 'bold';
        closeBtn.style.fontSize = '20px';
        closeBtn.style.marginLeft = '15px';
        
        closeBtn.addEventListener('click', function() {
            alert.style.display = 'none';
        });
        
        alert.insertBefore(closeBtn, alert.firstChild);
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            alert.style.opacity = '0';
            setTimeout(function() {
                alert.style.display = 'none';
            }, 500);
        }, 5000);
    });
});