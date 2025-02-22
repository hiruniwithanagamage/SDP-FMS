function login() {
    var Username = document.getElementById("username");
    var password = document.getElementById("password");

    
    var f = new FormData();
    f.append("u", Username.value);
    f.append("p", password.value);

    var request = new XMLHttpRequest();

    request.onreadystatechange = function() {
        if (request.readyState == 4 && request.status == 200) {
            var text = request.responseText;
            try {
                // Try to parse the response as JSON
                var response = JSON.parse(text);
                if (response.status === "success") {
                    
                    // // Show success message briefly before redirect
                    // msgContainer.innerHTML = "Login successful! Redirecting...";
                    // msgContainer.className = "message-container message-success";
                    // msgContainer.style.display = "block";
                    
                    // Redirect based on role
                    setTimeout(function() {
                        if (response.role === "admin") {
                            window.location = "views/admin/home-admin.php";
                        } else if (response.role === "member") {
                            window.location = "views/member/home-member.php";
                        } else if (response.role === "treasurer") {
                            window.location = "views/treasurer/home-treasurer.php";
                        } else if (response.role === "auditor") {
                            window.location = "views/home-auditor.php";
                        } else {
                            showError("Invalid role");
                        }
                    }, 1000); // Wait 1 second before redirect
                }
            } catch (e) {
                // If not JSON, it's an error message
                // showError(text);
                console.error("Error parsing response:", e);
                console.error("Response text:", text);
            }
        }
    };

    request.open("POST", "loginProcess.php", true);
    request.send(f);
}
