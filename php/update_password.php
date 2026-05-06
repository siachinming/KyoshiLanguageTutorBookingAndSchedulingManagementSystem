<?php
include "config.php";

$token = $_POST['token'];
$password = $_POST['password'];
$confirm = $_POST['confirm_password'];

if ($password !== $confirm) {
    die("❌ Password not match");
}

// check password strength
if (!preg_match('/^(?=.*[A-Z])(?=.*\d).{8,}$/', $password)) {
    die("❌ Weak password");
}

// find email from token
$sql = "SELECT * FROM password_resets WHERE token='$token'";
$result = $conn->query($sql);

if ($result->num_rows == 0) {
    die("❌ Invalid token");
}

$row = $result->fetch_assoc();
$email = $row['email'];

// hash new password
$hashed = password_hash($password, PASSWORD_DEFAULT);

// update user password
$conn->query("UPDATE users SET password='$hashed' WHERE email='$email'");

// delete token
$conn->query("DELETE FROM password_resets WHERE email='$email'");

echo "✅ Password updated successfully!";
?>