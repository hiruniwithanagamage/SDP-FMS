function login() {
    var Username = document.getElementById("username");
    var password = document.getElementById("password");
    var msgContainer = document.getElementById("msg");
    
    var f = new FormData();
    f.append("u", Username.value);
    f.append("p", password.value);

    var request = new XMLHttpRequest();

    request.onreadystatechange = function() {
        if (request.readyState == 4 && request.status == 200) {
            var response = JSON.parse(request.responseText);
            
            if (response.status === "success") {
                // Show success message briefly before redirect
                msgContainer.innerHTML = "Login successful! Redirecting...";
                msgContainer.className = "message-container message-success";
                msgContainer.style.display = "block";
                
                // Redirect based on role
                setTimeout(function() {
                    if (response.role === "admin") {
                        window.location = "views/admin/home-admin.php";
                    } else if (response.role === "member") {
                        window.location = "views/member/home-member.php";
                    } else if (response.role === "treasurer") {
                        window.location = "views/treasurer/home-treasurer.php";
                    } else if (response.role === "auditor") {
                        window.location = "views/auditor/home-auditor.php";
                    } else {
                        showError("Invalid role");
                    }
                }, 1000); // Wait 1 second before redirect
            } else if (response.status === "error") {
                // Display error message
                msgContainer.innerHTML = response.message;
                msgContainer.className = "message-container message-error";
                msgContainer.style.display = "block";
            }
        }
    };

    request.open("POST", "loginProcess.php", true);
    request.send(f);
}

// Helper function to display errors
function showError(message) {
    var msgContainer = document.getElementById("msg");
    msgContainer.innerHTML = message;
    msgContainer.className = "message-container message-error";
    msgContainer.style.display = "block";
}