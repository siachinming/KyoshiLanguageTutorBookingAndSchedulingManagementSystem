<?php
session_start();
include "config.php";

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$redirect = $_POST['redirect'] ?? 'student_dashboard.php';
$lang = $_POST['lang'] ?? '';
$remember_me = isset($_POST['remember_me']) ? true : false; // Add this line

// empty check
if (empty($email) || empty($password)) {
    $_SESSION['error'] = "Please enter email and password.";
    header("Location: login.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "No account found with that email.";
    header("Location: login.php");
    exit();
}

$user = $result->fetch_assoc();

if (!password_verify($password, $user['password'])) {
    $_SESSION['error'] = "Wrong password. Please try again.";
    header("Location: login.php");
    exit();
}

if ($user['role'] === 'tutor' && $user['status'] !== 'approved') {
    $_SESSION['error'] = "Your tutor account is still pending admin approval.";
    header("Location: login.php");
    exit();
}

$_SESSION['user_id']  = $user['id'];
$_SESSION['fullname'] = $user['fullname'];
$_SESSION['email']    = $user['email'];
$_SESSION['role']     = $user['role'];

if ($remember_me) {
    // Keep session alive for 30 days
    ini_set('session.gc_maxlifetime', 30 * 24 * 60 * 60);
    session_set_cookie_params(30 * 24 * 60 * 60);
    
    // Also set a session variable to mark remember me
    $_SESSION['remember_me'] = true;
}

if ($redirect === 'search_tutors.php') {

    if ($lang !== '') {
        header("Location: search_tutors.php?lang=" . urlencode($lang));
    } else {
        header("Location: search_tutors.php");
    }
    exit();
}

// normal role routing fallback
if ($user['role'] === 'admin') {
    header("Location: admin_dashboard.php");
} elseif ($user['role'] === 'student') {
    header("Location: student_dashboard.php");
} else {
    header("Location: tutor_dashboard.php");
}

exit();
?>