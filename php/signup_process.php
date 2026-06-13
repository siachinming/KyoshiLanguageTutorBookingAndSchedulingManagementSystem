<?php
session_start();
include "config.php";
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

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
    $_SESSION['error'] = "Invalid role selected. Please select a role";
    header("Location: signup.php");
    exit();
}

// ── Required fields ──────────────────────────────────────────────
if (empty($fullname) || empty($email) || empty($password) || empty($confirm)) {
    $_SESSION['error'] = "Please fill in all required fields.";
    header("Location: signup.php");
    exit();
}

// ── Phone number required for ALL roles ──────────────────────────
if (empty($phone)) {
    $_SESSION['error'] = "Phone number is required. Please enter your phone number.";
    header("Location: signup.php");
    exit();
}

// ── Valid email ──────────────────────────────────────────────────
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $_SESSION['error'] = "Please enter a valid email address. Try again";
    header("Location: signup.php");
    exit();
}

// ── Password match ───────────────────────────────────────────────
if ($password !== $confirm) {
    $_SESSION['error'] = "Passwords do not match. Try again";
    header("Location: signup.php");
    exit();
}

// ── Password strength ────────────────────────────────────────────
if (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*()\,\.?":{}|<>]).{8,}$/', $password)) {
    $_SESSION['error'] = "Password must be at least 8 characters and include an uppercase letter, a number, and a special character. Please try again.";
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

// ── Profile picture required for tutor only ──────────────────────
$profile_pic = null; // Default NULL for students

if ($role === 'tutor') {
    if (empty($_FILES['profile_pic']['name'])) {
        $_SESSION['error'] = "Tutors must upload a profile photo. Students need to see who they are booking with. Please try again.";
        header("Location: signup.php");
        exit();
    }
    
    // Upload profile picture for tutor
    $uploadDir = '../uploads/profiles/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $ext     = strtolower(pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];

    if (!in_array($ext, $allowed)) {
        $_SESSION['error'] = "Profile picture must be a JPG, PNG, or WEBP image. Please try again";
        header("Location: signup.php");
        exit();
    }

    if ($_FILES['profile_pic']['size'] > 2 * 1024 * 1024) {
        $_SESSION['error'] = "Profile picture must be under 2MB. Please try again";
        header("Location: signup.php");
        exit();
    }

    $filename = 'profile_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $filename)) {
        $profile_pic = $filename;
    } else {
        $_SESSION['error'] = "Failed to upload profile picture. Please try again.";
        header("Location: signup.php");
        exit();
    }
}

// ── Hash password ────────────────────────────────────────────────
$hashed = password_hash($password, PASSWORD_DEFAULT);
$verify_token = bin2hex(random_bytes(32));
$is_verified = 0;

// ── INSERT into users with proper NULL handling ──────────────────
if ($role === 'student') {
    // Student: phone required, profile_pic = NULL
    $stmt = $conn->prepare("
        INSERT INTO users (fullname, email, password, phone, role, profile_pic, status, verification_token, is_verified)
        VALUES (?, ?, ?, ?, ?, NULL, ?, ?, ?)
    ");
    $stmt->bind_param("sssssssi", $fullname, $email, $hashed, $phone, $role, $status, $verify_token, $is_verified);
} else {
    // Tutor: phone required, profile_pic has value
    $stmt = $conn->prepare("
        INSERT INTO users (fullname, email, password, phone, role, profile_pic, status, verification_token, is_verified)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssssssssi", $fullname, $email, $hashed, $phone, $role, $profile_pic, $status, $verify_token, $is_verified);
}

if (!$stmt->execute()) {
    $_SESSION['error'] = "Something went wrong creating your account. Please try again.";
    header("Location: signup.php");
    exit();
}

$newUserId = $conn->insert_id;
$stmt->close();

// ── If tutor, insert into tutor_profiles and tutor_certificates ─────────
if ($role === 'tutor') {
    $experience = intval($_POST['experience'] ?? 0);
    $rate       = trim($_POST['rate']         ?? '');
    $bio        = trim($_POST['bio']          ?? '');
    
    // ========== INSERT INTO TUTOR_PROFILES ==========
    $stmt2 = $conn->prepare("
        INSERT INTO tutor_profiles (user_id, experience, rate, bio)
        VALUES (?, ?, ?, ?)
    ");
    $stmt2->bind_param("idss", $newUserId, $experience, $rate, $bio);
    $stmt2->execute();
    $stmt2->close();
    
    // ========== INSERT INTO TUTOR_CERTIFICATES ==========
    if (isset($_FILES['certificates']) && !empty($_FILES['certificates']['name'][0])) {
        $uploadDir = '../uploads/certificates/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
        
        $certStmt = $conn->prepare("INSERT INTO tutor_certificates (tutor_id, certificate_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
        
        foreach ($_FILES['certificates']['name'] as $key => $name) {
            if ($_FILES['certificates']['error'][$key] == 0 && !empty($name)) {
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (in_array($ext, $allowed_ext)) {
                    $filename = 'cert_' . time() . '_' . $key . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (move_uploaded_file($_FILES['certificates']['tmp_name'][$key], $uploadDir . $filename)) {
                        $certStmt->bind_param("iss", $newUserId, $name, $filename);
                        $certStmt->execute();
                    }
                }
            }
        }
        $certStmt->close();
    }
    
    // Also support single file upload (backward compatibility)
    if (!empty($_FILES['certificate']['name'])) {
        $uploadDir = '../uploads/certificates/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
        
        $ext = strtolower(pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION));
        $filename = 'cert_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (move_uploaded_file($_FILES['certificate']['tmp_name'], $uploadDir . $filename)) {
            $certStmt = $conn->prepare("INSERT INTO tutor_certificates (tutor_id, certificate_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
            $certStmt->bind_param("iss", $newUserId, $_FILES['certificate']['name'], $filename);
            $certStmt->execute();
            $certStmt->close();
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

function sendWelcomeEmail($toEmail, $fullname, $role) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom(SMTP_USER, 'Kyoshi');
        $mail->addAddress($toEmail, $fullname);

        $mail->isHTML(true);
        $mail->Subject = 'Welcome to Kyoshi - Registration Confirmation';
        
        $roleText = ($role === 'tutor') ? 'Tutor' : 'Student';
        $statusText = ($role === 'tutor') ? 'Pending Approval' : 'Active';
        
        $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:500px;margin:auto;border:1px solid #eee;border-radius:10px;padding:20px;'>
                <h2 style='color:#38bdf8;'>Welcome to Kyoshi!</h2>
                <p>Dear <strong>" . htmlspecialchars($fullname) . "</strong>,</p>
                <p>Thank you for registering as a <strong>$roleText</strong> on Kyoshi Learning Platform.</p>
                
                <div style='background:#f0f8ff;padding:15px;border-radius:8px;margin:20px 0;'>
                    <p style='margin:0;'><strong>Your registered email:</strong><br>
                    <span style='font-size:16px;color:#38bdf8;'>" . htmlspecialchars($toEmail) . "</span></p>
                    <p style='margin:10px 0 0;font-size:12px;color:#666;'>Please use this email to log in to your account.</p>
                </div>
                
                " . ($role === 'tutor' ? "
                <div style='background:#fff3cd;padding:15px;border-radius:8px;margin:20px 0;'>
                    <p style='margin:0;'><strong>Account Status: $statusText</strong></p>
                    <p style='margin:5px 0 0;font-size:12px;'>Our admin will review your application within 24-48 hours. You will receive another email once approved.</p>
                </div>
                " : "
                <div style='background:#d4edda;padding:15px;border-radius:8px;margin:20px 0;'>
                    <p style='margin:0;'><strong>Account Status: $statusText</strong></p>
                    <p style='margin:5px 0 0;font-size:12px;'>You can now browse and book tutors!</p>
                </div>
                ") . "
                
                <p style='margin-top:20px;'>If you did not create this account, please ignore this email.</p>
                <hr style='margin:20px 0;'>
                <p style='font-size:12px;color:gray;'>This is an automated message, please do not reply.</p>
                <p style='font-size:12px;color:gray;'>&copy; 2025 Kyoshi Learning Platform</p>
            </div>
        ";

        $mail->AltBody = "Welcome to Kyoshi!\n\nYou have registered as a $roleText.\nYour registered email: $toEmail\nAccount Status: $statusText\n\nUse this email to log in.\n\nThis is an automated message, please do not reply.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Welcome email could not be sent. Mailer Error: {$mail->ErrorInfo}");
        return false;
    }
}

// Send welcome email
$emailSent = sendWelcomeEmail($email, $fullname, $role);

// ── Success ──────────────────────────────────────────────────────
if ($emailSent) {
    $_SESSION['success'] = "Account created successfully!<br>
    A confirmation email has been sent to <strong>" . htmlspecialchars($email) . "</strong><br>
    Please use this email to log in.<br>
    " . ($role === 'tutor' ? "Your account is pending approval. You will receive another email once verified." : "");
} else {
    $_SESSION['success'] = "Account created successfully!<br>
     Your registered email: <strong>" . htmlspecialchars($email) . "</strong><br>
    Please use this email to log in.<br>
    " . ($role === 'tutor' ? "Your account is pending approval." : "");
}

header("Location: login.php");
exit();
?>