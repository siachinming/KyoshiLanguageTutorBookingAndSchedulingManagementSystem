<?php
session_start();
include "config.php";

$token            = $_POST['token'] ?? '';
$password         = $_POST['password'] ?? '';
$confirm_password = $_POST['confirm_password'] ?? '';


if (empty($token) || empty($password) || empty($confirm_password)) {
    header("Location: reset_password.php?token=$token&error=Please+fill+in+all+fields.");
    exit();
}

if ($password !== $confirm_password) {
    header("Location: reset_password.php?token=$token&error=Passwords+do+not+match.");
    exit();
}

if (strlen($password) < 8) {
    header("Location: reset_password.php?token=$token&error=Password+must+be+at+least+6+characters.");
    exit();
}

$sql = "SELECT * FROM password_resets WHERE token = '$token'
        AND created_at >= NOW() - INTERVAL 1 HOUR";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    header("Location: forgotpassword.php?error=Reset+link+expired.+Please+request+a+new+one.");
    exit();
}

$row   = $result->fetch_assoc();
$email = $row['email'];

$hashed = password_hash($password, PASSWORD_DEFAULT);
$sql2   = "UPDATE users SET password = '$hashed' WHERE email = '$email'";
$conn->query($sql2);

$sql3 = "DELETE FROM password_resets WHERE token = '$token'";
$conn->query($sql3);

header("Location: login.php?success=Password+reset+successful!+You+can+now+log+in.");
exit();

$conn->close();
?>