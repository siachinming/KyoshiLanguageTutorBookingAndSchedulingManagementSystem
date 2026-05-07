<?php
session_start();
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: signup.php");
    exit();
}

$fullname   = trim($_POST['fullname']         ?? '');
$email      = trim($_POST['email']            ?? '');
$password   = $_POST['password']              ?? '';
$confirm    = $_POST['confirm_password']      ?? '';
$phone      = trim($_POST['phone']            ?? '');
$role       = trim($_POST['role']             ?? '');
$experience = intval($_POST['experience']     ?? 0);
$rate       = trim($_POST['rate']             ?? '');
$bio        = trim($_POST['bio']              ?? '');
$status = ($role === 'tutor') ? 'pending' : 'approved';

// ── Validate role ────────────────────────────────────────────────
if (!in_array($role, ['student', 'tutor'])) {
    $_SESSION['error'] = "Invalid role selected. Please go back and choose Student or Tutor.";
    header("Location: signup.php");
    exit();
}

// ── Required fields ──────────────────────────────────────────────
if (empty($fullname) || empty($email) || empty($password) || empty($confirm)) {
    $_SESSION['error'] = "Please fill in all required fields.";
    header("Location: signup.php");
    exit();
}

// ── Valid email ──────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = "Please enter a valid email address.";
    header("Location: signup.php");
    exit();
}

// ── Password match ───────────────────────────────────────────────
if ($password !== $confirm) {
    $_SESSION['error'] = "Passwords do not match.";
    header("Location: signup.php");
    exit();
}

// ── Password strength — matches frontend rules exactly ───────────
// 8+ chars, 1 uppercase, 1 number, 1 special character
if (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()\,\.?":{}|<>]).{8,}$/', $password)) {
    $_SESSION['error'] = "Password must be at least 8 characters and include an uppercase letter, a number, and a special character.";
    header("Location: signup.php");
    exit();
}

// ── Duplicate email check ────────────────────────────────────────
$check = $conn->prepare("SELECT id FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$check->store_result();
if ($check->num_rows > 0) {
    $_SESSION['error'] = "An account with this email already exists. Please log in instead.";
    header("Location: signup.php");
    exit();
}
$check->close();

// ── Profile picture upload (tutor only) ──────────────────────────
$profile_pic = '';
if ($role === 'tutor' && !empty($_FILES['profile_pic']['name'])) {
    $uploadDir = '../uploads/profiles/';

    // Create folder if it doesn't exist
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext     = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed)) {
        $_SESSION['error'] = "Profile picture must be a JPG, PNG, or WEBP image.";
        header("Location: signup.php");
        exit();
    }

    // 2MB max
    if ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
        $_SESSION['error'] = "Profile picture must be under 2MB.";
        header("Location: signup.php");
        exit();
    }

    $filename = 'profile_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $filename)) {
        $profile_pic = $filename;
    }
}
// ── Handle languages (tutor only) ───────────────────────────────
$languages = '';
if ($role === 'tutor' && !empty($_POST['languages'])) {
    $languages = implode(',', $_POST['languages']);
}

// ── Certificate upload (tutor only) ─────────────────────────────
$certificate = '';
if ($role === 'tutor' && !empty($_FILES['certificate']['name'])) {
    $uploadDir = '../uploads/certificates/';

    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext     = strtolower(pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION));
    $filename = 'cert_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (move_uploaded_file($_FILES['certificate']['tmp_name'], $uploadDir . $filename)) {
        $certificate = $filename;
    }
}

// ── Hash password ────────────────────────────────────────────────
$hashed = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("
    INSERT INTO users 
    (fullname, email, password, phone, role, experience, rate, bio, profile_pic, languages, language_certificate, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$stmt->bind_param(
    "sssssdssssss",
    $fullname,
    $email,
    $hashed,
    $phone,
    $role,
    $experience,
    $rate,
    $bio,
    $profile_pic,
    $languages,
    $certificate,
    $status
);

if (!$stmt->execute()) {
    $_SESSION['error'] = "Something went wrong creating your account. Please try again.";
    header("Location: signup.php");
    exit();
}
$newUserId = $conn->insert_id;  
$stmt->close();

if ($role === 'student' && !empty($_POST['preferred_languages'])) {
    $langStmt = $conn->prepare("INSERT INTO student_preferences (user_id, language) VALUES (?, ?)");
    foreach ($_POST['preferred_languages'] as $lang) {
        $lang = trim($lang);
        $langStmt->bind_param("is", $newUserId, $lang);
        $langStmt->execute();
    }
    $langStmt->close();
}

// ── Success ──────────────────────────────────────────────────────
$_SESSION['success'] = "Account created successfully! Please log in.";
header("Location: login.php");
exit();
?>