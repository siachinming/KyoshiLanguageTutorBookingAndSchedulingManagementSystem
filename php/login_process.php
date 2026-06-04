<?php
session_start();
include "config.php";

$email = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';
$redirect = $_POST['redirect'] ?? 'student_dashboard.php';
$lang = $_POST['lang'] ?? '';
$remember_me = isset($_POST['remember_me']) ? true : false;

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

// ========== ADD THESE STATUS CHECKS ==========

// Check for deactivated/inactive account
if ($user['status'] === 'inactive') {
    $_SESSION['error'] = "Your account has been deactivated. Please contact support (+6012-3344566) to reactivate your account.";
    header("Location: login.php");
    exit();
}

// Check for pending tutor approval
if ($user['role'] === 'tutor' && ($user['status'] === 'pending' || $user['status'] !== 'approved')) {
    $_SESSION['error'] = "Your tutor account is pending admin approval. Please wait for verification. You will receive an email once approved.";
    header("Location: login.php");
    exit();
}

// Check for suspended account
if ($user['status'] === 'suspended') {
    $_SESSION['error'] = "Your account has been suspended. Please contact support for more information.";
    header("Location: login.php");
    exit();
}

// Check for rejected account
if ($user['status'] === 'rejected') {
    $_SESSION['error'] = "Your account application has been rejected. Please contact support for more information.";
    header("Location: login.php");
    exit();
}

// Final check - only active accounts can proceed
if ($user['status'] !== 'active' && $user['status'] !== 'approved') {
    $_SESSION['error'] = "Your account is not active. Current status: " . ucfirst($user['status']);
    header("Location: login.php");
    exit();
}

// ========== END STATUS CHECKS ==========

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