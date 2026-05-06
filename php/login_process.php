<?php
session_start();
include "config.php";

$email = $_POST['email'];
$password = $_POST['password'];

$sql = "SELECT * FROM users WHERE email = '$email'";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $user = $result->fetch_assoc();

    if (password_verify($password, $user['password'])) {

        $_SESSION['user_id']  = $user['id'];
        $_SESSION['fullname'] = $user['fullname'];
        $_SESSION['email']    = $user['email'];
        $_SESSION['role']     = $user['role'];

        if ($user['role'] == 'admin') {
            header("Location: admin_dashboard.php");
        } else if ($user['role'] == 'student') {
            header("Location: student_dashboard.php");
        } else if ($user['role'] == 'tutor') {
            header("Location: tutor_dashboard.php");
        } else {
            header("Location: login.php?error=Unknown+role");
        }

        exit();

    } else {
        // ✅ Wrong password — send back to login with error
        header("Location: login.php?error=Wrong+password.+Please+try+again.");
        exit();
    }

} else {
    // ✅ User not found — send back to login with error
    header("Location: login.php?error=No+account+found+with+that+email.");
    exit();
}

$conn->close();
?>