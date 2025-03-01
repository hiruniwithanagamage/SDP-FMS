<?php
session_start();
require "config/database.php";

$Username = $_POST["u"];
$password = $_POST["p"];

if(empty($Username)){
    echo json_encode(["status" => "error", "message" => "Please enter your Username"]);
}else if(strlen($Username) > 100){
    echo json_encode(["status" => "error", "message" => "Username must have less than 100 characters"]);
}else if (!preg_match("/^[a-zA-Z0-9_]{3,100}$/", $Username)) {
    echo json_encode(["status" => "error", "message" => "Invalid Username"]);
}else if(empty($password)){
    echo json_encode(["status" => "error", "message" => "Please enter your Password"]);
}else if(strlen($password) < 5 || strlen($password) > 20){
    echo json_encode(["status" => "error", "message" => "Invalid Password"]);
}else{
    // Using procedural function
    $rs = search("SELECT u.*, 
            CASE
                WHEN u.Admin_AdminID IS NOT NULL THEN 'admin'
                WHEN u.Member_MemberID IS NOT NULL THEN 'member'
                WHEN u.Treasurer_TreasurerID IS NOT NULL THEN 'treasurer'
                WHEN u.Auditor_AuditorID IS NOT NULL THEN 'auditor'
            END as role,
            COALESCE(u.Admin_AdminID, u.Member_MemberID, u.Treasurer_TreasurerID, u.Auditor_AuditorID) as role_id
        FROM `User` u 
        WHERE `Username`='".$Username."'");
        
    $n = $rs->num_rows;

    if($n == 1){
        $d = $rs->fetch_assoc();

        if ($password == $d['Password']) {
            $_SESSION["u"] = $d;
            $_SESSION["role"] = $d["role"];
            $_SESSION["role_id"] = $d["role_id"];
            $_SESSION["user_id"] = $d["UserId"]; 
            $_SESSION["member_id"] = $d["Member_MemberID"];
            $_SESSION["admin_id"] = $d["Admin_AdminID"];
            $_SESSION["treasurer_id"] = $d["Treasurer_TreasurerID"];
            $_SESSION["auditor_id"] = $d["Auditor_AuditorID"];

            // Return success with role for redirection
            echo json_encode([
                "status" => "success",
                "role" => $d["role"]
            ]);
            
        } else {
            echo json_encode(["status" => "error", "message" => "Invalid Username or Password"]);
        }
    }else{
        echo json_encode(["status" => "error", "message" => "Invalid Username or Password"]);
    } 
}
?>