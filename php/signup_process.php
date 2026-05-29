<?php
session_start();
include "config.php";

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: signup.php");
    exit();
}

$fullname = trim($_POST['fullname'] ?? '');
$email    = trim($_POST['email']    ?? '');
$password = $_POST['password']      ?? '';
$confirm  = $_POST['confirm_password'] ?? '';
$phone    = trim($_POST['phone']    ?? '');
$role     = trim($_POST['role']     ?? '');
$status   = ($role === 'tutor') ? 'pending' : 'approved';

// ── Validate role ────────────────────────────────────────────────
if (!in_array($role, ['student', 'tutor'])) {
    $_SESSION['error'] = "Invalid role selected.";
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

// ── Password strength ────────────────────────────────────────────
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

// ── Profile picture upload (all roles) ───────────────────────────
$profile_pic = '';
if (!empty($_FILES['profile_pic']['name'])) {
    $uploadDir = '../uploads/profiles/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext     = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed)) {
        $_SESSION['error'] = "Profile picture must be a JPG, PNG, or WEBP image.";
        header("Location: signup.php");
        exit();
    }

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

// ── Hash password ────────────────────────────────────────────────
$hashed = password_hash($password, PASSWORD_DEFAULT);
$verify_token = bin2hex(random_bytes(32));
$is_verified = 0;

$stmt = $conn->prepare("
    INSERT INTO users (fullname, email, password, phone, role, profile_pic, status, verification_token, is_verified)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->bind_param("ssssssssi", $fullname, $email, $hashed, $phone, $role, $profile_pic, $status, $verify_token, $is_verified);
if (!$stmt->execute()) {
    $_SESSION['error'] = "Something went wrong creating your account. Please try again.";
    header("Location: signup.php");
    exit();
}

$newUserId = $conn->insert_id;
$stmt->close();

// ── If tutor, insert into tutor_profiles ─────────────────────────
if ($role === 'tutor') {
    $experience = intval($_POST['experience'] ?? 0);
    $rate       = trim($_POST['rate']         ?? '');
    $bio        = trim($_POST['bio']          ?? '');
    $certificate = '';

    // Certificate upload
    if (!empty($_FILES['certificate']['name'])) {
        $uploadDir = '../uploads/certificates/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = strtolower(pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION));
        $filename = 'cert_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

        if (move_uploaded_file($_FILES['certificate']['tmp_name'], $uploadDir . $filename)) {
            $certificate = $filename;
        }
    }

    // Insert tutor languages with proficiency
    $tutor_languages = $_POST['tutor_languages'] ?? [];
    $langStmt = $conn->prepare("INSERT INTO tutor_languages (user_id, language, proficiency_level) VALUES (?, ?, ?)");
    
    $proficiency_map = [
        'English' => $_POST['tutor_proficiency_english'] ?? 'intermediate',
        'Japanese' => $_POST['tutor_proficiency_japanese'] ?? 'intermediate',
        'Mandarin' => $_POST['tutor_proficiency_mandarin'] ?? 'intermediate',
        'Malay' => $_POST['tutor_proficiency_malay'] ?? 'intermediate',
        'Korean' => $_POST['tutor_proficiency_korean'] ?? 'intermediate'
    ];
    
    foreach ($tutor_languages as $lang) {
        $lang = trim($lang);
        $proficiency = $proficiency_map[$lang] ?? 'intermediate';
        $langStmt->bind_param("iss", $newUserId, $lang, $proficiency);
        $langStmt->execute();
    }
    $langStmt->close();

    // Insert tutor teaching modes
    $teaching_modes = $_POST['tutor_teaching_mode'] ?? [];
    if (!empty($teaching_modes)) {
        $modeStmt = $conn->prepare("INSERT INTO tutor_teaching_modes (user_id, mode) VALUES (?, ?)");
        foreach ($teaching_modes as $mode) {
            $mode = trim($mode);
            $modeStmt->bind_param("is", $newUserId, $mode);
            $modeStmt->execute();
        }
        $modeStmt->close();
    }

    // Insert tutor location if face to face selected
    if (in_array('face_to_face', $teaching_modes) && !empty($_POST['tutor_location'])) {
        $loc = trim($_POST['tutor_location']);
        $locStmt = $conn->prepare("INSERT INTO user_locations (user_id, location, location_type) VALUES (?, ?, 'teaching')");
        $locStmt->bind_param("is", $newUserId, $loc);
        $locStmt->execute();
        $locStmt->close();
    }

    $stmt2 = $conn->prepare("
        INSERT INTO tutor_profiles (user_id, experience, rate, bio, language_certificate)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt2->bind_param("idsss", $newUserId, $experience, $rate, $bio, $certificate);
    $stmt2->execute();
    $stmt2->close();
}

// ── If student, insert languages with proficiency ────────────────
if ($role === 'student') {
    $student_languages = $_POST['student_languages'] ?? [];
    
    $langStmt = $conn->prepare("INSERT INTO student_preferences (user_id, language, proficiency_level) VALUES (?, ?, ?)");
    
    $proficiency_map = [
        'English' => $_POST['student_proficiency_english'] ?? 'beginner',
        'Japanese' => $_POST['student_proficiency_japanese'] ?? 'beginner',
        'Mandarin' => $_POST['student_proficiency_mandarin'] ?? 'beginner',
        'Malay' => $_POST['student_proficiency_malay'] ?? 'beginner',
        'Korean' => $_POST['student_proficiency_korean'] ?? 'beginner'
    ];
    
    foreach ($student_languages as $lang) {
        $lang = trim($lang);
        $proficiency = $proficiency_map[$lang] ?? 'beginner';
        $langStmt->bind_param("iss", $newUserId, $lang, $proficiency);
        $langStmt->execute();
    }
    $langStmt->close();

    // Insert student learning modes
    $learning_modes = $_POST['student_learning_mode'] ?? [];
    if (!empty($learning_modes)) {
        $modeStmt = $conn->prepare("INSERT INTO student_learning_modes (user_id, mode) VALUES (?, ?)");
        foreach ($learning_modes as $mode) {
            $mode = trim($mode);
            $modeStmt->bind_param("is", $newUserId, $mode);
            $modeStmt->execute();
        }
        $modeStmt->close();
    }

    // Insert student location if face to face selected
    if (in_array('face_to_face', $learning_modes) && !empty($_POST['student_location'])) {
        $loc = trim($_POST['student_location']);
        $locStmt = $conn->prepare("INSERT INTO user_locations (user_id, location, location_type) VALUES (?, ?, 'learning')");
        $locStmt->bind_param("is", $newUserId, $loc);
        $locStmt->execute();
        $locStmt->close();
    }
}

// ── Success ──────────────────────────────────────────────────────
$_SESSION['success'] = "Account created successfully! Please log in.";
header("Location: signup.php");
exit();
?>