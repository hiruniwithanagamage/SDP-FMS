function login() {
    var Username = document.getElementById("username");
    var password = document.getElementById("password");
    var msgContainer = document.getElementById("msg");
    
    // Clear any existing message
    msgContainer.style.display = "none";
    
    var f = new FormData();
    f.append("u", Username.value);
    f.append("p", password.value);

    var request = new XMLHttpRequest();

    request.onreadystatechange = function() {
        if (request.readyState == 4) {
            console.log("Response received. Status:", request.status);
            console.log("Response text:", request.responseText);
            
            if (request.status == 200 || request.status == 400) {
                try {
                    var response = JSON.parse(request.responseText);
                    console.log("Parsed response:", response);
                    
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
                        console.log("Displaying error:", response.message);
                        showError(response.message);
                    }
                } catch (e) {
                    // Handle JSON parsing errors
                    console.error("JSON parsing error:", e);
                    console.log("Response text that failed to parse:", request.responseText);
                    showError("An unexpected error occurred. Please try again.");
                }
            } else {
                // Handle other HTTP status codes
                console.error("HTTP error:", request.status);
                showError("Server error. Please try again later.");
            }
        }
    };

    request.open("POST", "loginProcess.php", true);
    request.send(f);
}

// Helper function to display errors
function showError(message) {
    var msgContainer = document.getElementById("msg");
    console.log("showError called with message:", message);
    console.log("Current msgContainer:", msgContainer);
    
    if (msgContainer) {
        msgContainer.innerHTML = message;
        msgContainer.className = "message-container message-error";
        msgContainer.style.display = "block";
        console.log("Message container updated with error message");
    } else {
        console.error("msgContainer not found!");
    }
}