<?php
session_start();
include 'config.php';
include 'insert_notification.php';
$assetBase = '../assets/img';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'tutor') {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];

$stmt = $conn->prepare("
    SELECT u.*, tp.experience, tp.rate, tp.bio, tp.language_certificate,
           bd.bank_name, bd.bank_account_number, bd.bank_account_name,
           GROUP_CONCAT(DISTINCT tq.qualification_name SEPARATOR ' | ') as qualifications
    FROM users u
    LEFT JOIN tutor_profiles tp ON u.id = tp.user_id
    LEFT JOIN tutor_bank_details bd ON u.id = bd.tutor_id
    LEFT JOIN tutor_qualifications tq ON u.id = tq.tutor_id
    WHERE u.id = ? AND u.role = 'tutor'
    GROUP BY u.id
");
$stmt->bind_param("i", $userID);
$stmt->execute();
$tutor = $stmt->get_result()->fetch_assoc();

if (!$tutor) {
    header("Location: login.php");
    exit();
}

// Get tutor bank details (multiple)
$bankStmt = $conn->prepare("
    SELECT id, bank_name, bank_account_number, bank_account_name, is_default 
    FROM tutor_bank_details 
    WHERE tutor_id = ? 
    ORDER BY is_default DESC, id ASC
");
$bankStmt->bind_param("i", $userID);
$bankStmt->execute();
$bankAccounts = $bankStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$hasBankAccounts = count($bankAccounts) > 0;


$displayName = $tutor['fullname'];
$firstName = explode(' ', trim($displayName))[0];
$profilePic = !empty($tutor['profile_pic'])
    ? '../uploads/profiles/' . $tutor['profile_pic'] . '?v=' . time()
    : $assetBase . '/profile-tutor.png';

// Get tutor languages with proficiency
$langStmt = $conn->prepare("SELECT language, proficiency_level FROM tutor_languages WHERE user_id = ?");
$langStmt->bind_param("i", $userID);
$langStmt->execute();
$tutorLanguages = $langStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get teaching modes
$modeStmt = $conn->prepare("SELECT mode FROM tutor_teaching_modes WHERE user_id = ?");
$modeStmt->bind_param("i", $userID);
$modeStmt->execute();
$teachingModes = $modeStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get teaching location
$locStmt = $conn->prepare("SELECT location FROM user_locations WHERE user_id = ? AND location_type = 'teaching'");
$locStmt->bind_param("i", $userID);
$locStmt->execute();
$teachingLocation = $locStmt->get_result()->fetch_assoc();

// Get tutor availability schedule
$availStmt = $conn->prepare("
    SELECT id, day_of_week, start_time, end_time 
    FROM tutor_availability 
    WHERE tutor_id = ? 
    ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
");
$availStmt->bind_param("i", $userID);
$availStmt->execute();
$availability = $availStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Get certificates
$certStmt = $conn->prepare("
    SELECT id, certificate_name, file_path, uploaded_at, status
    FROM tutor_certificates 
    WHERE tutor_id = ? 
    ORDER BY uploaded_at DESC
");
$certStmt->bind_param("i", $userID);
$certStmt->execute();
$certificates = $certStmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Check for active bookings via AJAX (for the deactivate modal)
if (isset($_GET['check_upcoming']) && $_GET['check_upcoming'] == 1) {
    header('Content-Type: application/json');
    
    // Check for accepted/confirmed classes (cannot deactivate)
    $activeCheck = $conn->prepare("
        SELECT 
            COUNT(*) as total_count,
            GROUP_CONCAT(DISTINCT DATE_FORMAT(booking_date, '%d %M %Y') ORDER BY booking_date SEPARATOR ', ') as dates
        FROM bookings 
        WHERE tutor_id = ? 
        AND status IN ('accepted', 'confirmed') 
        AND booking_date >= CURDATE()
    ");
    $activeCheck->bind_param("i", $userID);
    $activeCheck->execute();
    $activeResult = $activeCheck->get_result()->fetch_assoc();
    
    // Check for pending classes (will be cancelled)
    $pendingCheck = $conn->prepare("
        SELECT COUNT(*) as count
        FROM bookings 
        WHERE tutor_id = ? 
        AND status = 'pending'
        AND booking_date >= CURDATE()
    ");
    $pendingCheck->bind_param("i", $userID);
    $pendingCheck->execute();
    $pendingResult = $pendingCheck->get_result()->fetch_assoc();
    
    // Send ONE JSON response
    echo json_encode([
        'has_active' => ($activeResult['total_count'] ?? 0) > 0,
        'active_count' => $activeResult['total_count'] ?? 0,
        'active_dates' => $activeResult['dates'] ?? '',
        'pending_count' => $pendingResult['count'] ?? 0
    ]);
    exit();
}

// Get statistics
$stats = $conn->prepare("
    SELECT 
        COUNT(*) as total_sessions,
        SUM(CASE WHEN b.status = 'completed' THEN 1 ELSE 0 END) as completed_sessions,
        ROUND(AVG(r.rating), 1) as avg_rating
    FROM bookings b
    LEFT JOIN ratings r ON b.id = r.booking_id AND r.tutor_id = b.tutor_id
    WHERE b.tutor_id = ?
");
$stats->bind_param("i", $userID);
$stats->execute();
$stats_result = $stats->get_result()->fetch_assoc();

// Function to send email
function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email failed: " . $e->getMessage());
        return false;
    }
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'update_profile') {
    $fullname = $_POST['fullname'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $bio = $_POST['bio'] ?? '';
    $experience = intval($_POST['experience'] ?? 0);
    $rate = floatval($_POST['rate'] ?? 0);
    
    $changes_made = false;
    
    // Check if users table needs update
    if ($fullname != $tutor['fullname'] || $phone != ($tutor['phone'] ?? '')) {
        $sql1 = "UPDATE users SET fullname = '$fullname', phone = '$phone' WHERE id = $userID";
        mysqli_query($conn, $sql1);
        $changes_made = true;
    }
    
    // Check if tutor_profiles needs update
    if ($bio != ($tutor['bio'] ?? '') || $experience != ($tutor['experience'] ?? 0) || $rate != ($tutor['rate'] ?? 0)) {
        $checkResult = mysqli_query($conn, "SELECT user_id FROM tutor_profiles WHERE user_id = $userID");
        if (mysqli_num_rows($checkResult) > 0) {
            $sql2 = "UPDATE tutor_profiles SET bio = '$bio', experience = $experience, rate = $rate WHERE user_id = $userID";
            mysqli_query($conn, $sql2);
        } else {
            $sql2 = "INSERT INTO tutor_profiles (user_id, bio, experience, rate) VALUES ($userID, '$bio', $experience, $rate)";
            mysqli_query($conn, $sql2);
        }
        $changes_made = true;
    }
    
    // Check languages - compare with existing
    $languages = $_POST['languages'] ?? [];
    $proficiencies = $_POST['proficiency'] ?? [];
    
    // Get existing languages
    $existingLangs = [];
    $langCheck = mysqli_query($conn, "SELECT language, proficiency_level FROM tutor_languages WHERE user_id = $userID");
    while ($row = mysqli_fetch_assoc($langCheck)) {
        $existingLangs[] = $row;
    }
    
    // Check if languages changed
    $newLangs = [];
    for ($i = 0; $i < count($languages); $i++) {
        if (!empty(trim($languages[$i]))) {
            $newLangs[] = ['language' => $languages[$i], 'proficiency_level' => $proficiencies[$i] ?? 'beginner'];
        }
    }
    
    if (json_encode($existingLangs) != json_encode($newLangs)) {
        // Delete all existing languages
        mysqli_query($conn, "DELETE FROM tutor_languages WHERE user_id = $userID");
        
        // Insert new languages
        for ($i = 0; $i < count($languages); $i++) {
            $lang = trim($languages[$i]);
            $prof = $proficiencies[$i] ?? 'beginner';
            if (!empty($lang)) {
                mysqli_query($conn, "INSERT INTO tutor_languages (user_id, language, proficiency_level) VALUES ($userID, '$lang', '$prof')");
            }
        }
        $changes_made = true;
    }
    
    // Check teaching modes
    $modes = $_POST['teaching_modes'] ?? [];
    $existingModes = [];
    $modeCheck = mysqli_query($conn, "SELECT mode FROM tutor_teaching_modes WHERE user_id = $userID");
    while ($row = mysqli_fetch_assoc($modeCheck)) {
        $existingModes[] = $row['mode'];
    }
    
    if (json_encode($existingModes) != json_encode($modes)) {
        mysqli_query($conn, "DELETE FROM tutor_teaching_modes WHERE user_id = $userID");
        foreach ($modes as $mode) {
            mysqli_query($conn, "INSERT INTO tutor_teaching_modes (user_id, mode) VALUES ($userID, '$mode')");
        }
        $changes_made = true;
    }
    
    // Check teaching location
    $teaching_location = $_POST['teaching_location'] ?? '';
    $existingLocation = $teachingLocation['location'] ?? '';
    
    if ($teaching_location != $existingLocation) {
        $locCheck = mysqli_query($conn, "SELECT COUNT(*) as count FROM user_locations WHERE user_id = $userID AND location_type = 'teaching'");
        $locResult = mysqli_fetch_assoc($locCheck);
        
        if ($locResult['count'] > 0) {
            mysqli_query($conn, "UPDATE user_locations SET location = '$teaching_location' WHERE user_id = $userID AND location_type = 'teaching'");
        } else {
            mysqli_query($conn, "INSERT INTO user_locations (user_id, location, location_type) VALUES ($userID, '$teaching_location', 'teaching')");
        }
        $changes_made = true;
    }
    
    // Only show success message if changes were actually made
    if ($changes_made) {
        $_SESSION['success_message'] = 'Profile updated successfully!';
    } else {
        $_SESSION['success_message'] = 'No changes were made to your profile.';
    }
    
    header("Location: teacher_profile.php");
    exit();
}// SAVE/ADD Bank Account (WITH DUPLICATE CHECK)
if ($_POST['action'] === 'save_bank') {
    $bankId = intval($_POST['bank_id'] ?? 0);
    $bankName = trim($_POST['bank_name'] ?? '');
    $bankAccountNumber = trim($_POST['bank_account_number'] ?? '');
    $bankAccountName = trim($_POST['bank_account_name'] ?? '');
    
    $errors = [];
    if (empty($bankName)) $errors[] = "Bank name is required";
    if (empty($bankAccountNumber)) $errors[] = "Account number is required";
    if (empty($bankAccountName)) $errors[] = "Account holder name is required";
    
    if (empty($errors)) {
        // CHECK FOR DUPLICATE (same account number)
        $dupStmt = $conn->prepare("
            SELECT id FROM tutor_bank_details 
            WHERE tutor_id = ? AND bank_account_number = ? AND id != ?
        ");
        $dupStmt->bind_param("isi", $userID, $bankAccountNumber, $bankId);
        $dupStmt->execute();
        $duplicate = $dupStmt->get_result()->fetch_assoc();
        
        if ($duplicate) {
            $_SESSION['error_message'] = "This bank account number already exists! Please use a different account.";
            header("Location: teacher_profile.php");
            exit();
        }
        
        $checkCount = $conn->prepare("SELECT COUNT(*) as count FROM tutor_bank_details WHERE tutor_id = ?");
        $checkCount->bind_param("i", $userID);
        $checkCount->execute();
        $count = $checkCount->get_result()->fetch_assoc()['count'];
        
        if ($bankId > 0) {
            // Update existing
            $stmt = $conn->prepare("
                UPDATE tutor_bank_details SET 
                    bank_name = ?, bank_account_number = ?, bank_account_name = ? 
                WHERE id = ? AND tutor_id = ?
            ");
            $stmt->bind_param("sssii", $bankName, $bankAccountNumber, $bankAccountName, $bankId, $userID);
        } else {
            // Check limit (max 3)
            if ($count >= 3) {
                $_SESSION['error_message'] = "Maximum 3 bank accounts allowed.";
                header("Location: teacher_profile.php");
                exit();
            }
            // First account becomes default
            $isDefault = ($count == 0) ? 1 : 0;
            $stmt = $conn->prepare("
                INSERT INTO tutor_bank_details (tutor_id, bank_name, bank_account_number, bank_account_name, is_default) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("isssi", $userID, $bankName, $bankAccountNumber, $bankAccountName, $isDefault);
        }
        
        if ($stmt->execute()) {
            $_SESSION['success_message'] = $bankId > 0 ? 'Bank details updated!' : 'Bank account added!';
        } else {
            $_SESSION['error_message'] = 'Error saving bank details.';
        }
    } else {
        $_SESSION['error_message'] = implode(", ", $errors);
    }
    header("Location: teacher_profile.php");
    exit();
}

// DELETE Bank Account
if ($_POST['action'] === 'delete_bank') {
    $bankId = intval($_POST['bank_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM tutor_bank_details WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $bankId, $userID);
    if ($stmt->execute()) {
        $_SESSION['success_message'] = 'Bank account removed.';
    }
    header("Location: teacher_profile.php");
    exit();
}

// SET DEFAULT Bank Account
if ($_POST['action'] === 'set_default_bank') {
    $bankId = intval($_POST['bank_id'] ?? 0);
    $conn->query("UPDATE tutor_bank_details SET is_default = 0 WHERE tutor_id = $userID");
    $stmt = $conn->prepare("UPDATE tutor_bank_details SET is_default = 1 WHERE id = ? AND tutor_id = ?");
    $stmt->bind_param("ii", $bankId, $userID);
    $stmt->execute();
    $_SESSION['success_message'] = 'Default bank account updated.';
    header("Location: teacher_profile.php");
    exit();
}
    if ($_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        
        if (!password_verify($current, $tutor['password'])) {
            $_SESSION['error_message'] = 'Current password is incorrect.';
        } elseif ($new !== $confirm) {
            $_SESSION['error_message'] = 'New passwords do not match.';
        } elseif (strlen($new) < 8) {
            $_SESSION['error_message'] = 'Password must be at least 8 characters.';
        } elseif (!preg_match('/[A-Z]/', $new)) {
            $_SESSION['error_message'] = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[0-9]/', $new)) {
            $_SESSION['error_message'] = 'Password must contain at least one number.';
        } else {
            $hashed = password_hash($new, PASSWORD_DEFAULT);
            $updatePass = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updatePass->bind_param("si", $hashed, $userID);
            $updatePass->execute();
            
            $emailBody = "
            <div style='font-family: Arial, sans-serif; max-width: 500px; margin: auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 10px;'>
                <h2 style='color: #E75A9B;'>Password Changed</h2>
                <p>Dear {$tutor['fullname']},</p>
                <p>Your Kyoshi tutor account password has been successfully changed.</p>
                <p>If you did not make this change, please contact support immediately.</p>
                <hr>
                <p style='font-size: 12px; color: #666;'>Kyoshi Learning Platform</p>
            </div>";
            sendEmail($tutor['email'], 'Your Password Has Been Changed', $emailBody);
            $_SESSION['success_message'] = 'Password changed successfully!';
        }
        header("Location: teacher_profile.php");
        exit();
    }
    
    if ($_POST['action'] === 'upload_certificate') {
        $cert_name = $_POST['certificate_name'] ?? '';
        if (empty($cert_name)) {
            $_SESSION['error_message'] = 'Please enter a certificate name.';
        } elseif (isset($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = '../uploads/certificates/';
            if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
            $ext = strtolower(pathinfo($_FILES['certificate_file']['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
            if (in_array($ext, $allowed)) {
                $filename = 'cert_' . $userID . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['certificate_file']['tmp_name'], $upload_dir . $filename);
                $insertCert = $conn->prepare("INSERT INTO tutor_certificates (tutor_id, certificate_name, file_path, uploaded_at, status) VALUES (?, ?, ?, NOW(), 'pending')");
                $insertCert->bind_param("iss", $userID, $cert_name, $filename);
                $insertCert->execute();
                $_SESSION['success_message'] = 'Certificate uploaded! Pending admin verification.';
            } else {
                $_SESSION['error_message'] = 'Only PDF, JPG, PNG files are allowed.';
            }
        } else {
            $_SESSION['error_message'] = 'Please select a file to upload.';
        }
        header("Location: teacher_profile.php");
        exit();
    }
    
    if ($_POST['action'] === 'delete_certificate') {
        $cert_id = intval($_POST['cert_id'] ?? 0);
        $getCert = $conn->prepare("SELECT file_path FROM tutor_certificates WHERE id = ? AND tutor_id = ?");
        $getCert->bind_param("ii", $cert_id, $userID);
        $getCert->execute();
        $cert = $getCert->get_result()->fetch_assoc();
        if ($cert) {
            $file_path = '../uploads/certificates/' . $cert['file_path'];
            if (file_exists($file_path)) unlink($file_path);
            $conn->query("DELETE FROM tutor_certificates WHERE id = $cert_id AND tutor_id = $userID");
            $_SESSION['success_message'] = 'Certificate deleted successfully.';
        }
        header("Location: teacher_profile.php");
        exit();
    }

    // Handle direct profile picture upload
if ($_POST['action'] === 'upload_profile_pic') {
    header('Content-Type: application/json');
    
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/profiles/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        
        $file = $_FILES['profile_pic'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        
        if (!in_array($ext, $allowed)) {
            echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF allowed.']);
            exit();
        }
        
        // Check file size (5MB max)
        if ($file['size'] > 5 * 1024 * 1024) {
            echo json_encode(['success' => false, 'error' => 'File too large. Max 5MB.']);
            exit();
        }
        
        // Delete old profile picture if exists
        if (!empty($tutor['profile_pic']) && file_exists($upload_dir . $tutor['profile_pic'])) {
            unlink($upload_dir . $tutor['profile_pic']);
        }
        
        // Generate new filename
        $filename = 'tutor_' . $userID . '_' . time() . '.' . $ext;
        
        if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
            // Update database
            $updatePic = $conn->prepare("UPDATE users SET profile_pic = ? WHERE id = ?");
            $updatePic->bind_param("si", $filename, $userID);
            $updatePic->execute();
            
            echo json_encode([
                'success' => true, 
                'new_image' => '../uploads/profiles/' . $filename
            ]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to save file.']);
            exit();
        }
    }
    
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit();
}
    
   if ($_POST['action'] === 'deactivate_account') {
    $reason = $_POST['deactivation_reason'] ?? '';
    
    // Check for ACCEPTED or CONFIRMED classes (CANNOT deactivate)
    $activeCheck = $conn->prepare("
        SELECT COUNT(*) as count, 
               GROUP_CONCAT(DISTINCT status SEPARATOR ', ') as statuses,
               GROUP_CONCAT(DATE_FORMAT(booking_date, '%d %M %Y') SEPARATOR ', ') as dates
        FROM bookings 
        WHERE tutor_id = ? 
        AND status IN ('accepted', 'confirmed') 
        AND booking_date >= CURDATE()
    ");
    $activeCheck->bind_param("i", $userID);
    $activeCheck->execute();
    $activeResult = $activeCheck->get_result()->fetch_assoc();
    $activeCount = $activeResult['count'];
    
    // If there are accepted or confirmed classes, CANNOT deactivate
    if ($activeCount > 0) {
        $_SESSION['error_message'] = "You cannot deactivate because you have $activeCount active booking(s) that are accepted/confirmed. Please complete these classes first.";
        header("Location: teacher_profile.php");
        exit();
    }
    
    // Cancel all pending bookings (these can be cancelled)
    $cancelPending = $conn->prepare("
        UPDATE bookings 
        SET status = 'cancelled', 
            cancellation_reason = 'Tutor account deactivated',
            cancelled_at = NOW()
        WHERE tutor_id = ? 
        AND status = 'pending'
        AND booking_date >= CURDATE()
    ");
    $cancelPending->bind_param("i", $userID);
    $cancelPending->execute();
    
    $cancelledCount = $conn->affected_rows;
    
    // Proceed with deactivation
    $updateStmt = $conn->prepare("UPDATE users SET status = 'inactive', deactivated_at = NOW(), deactivation_reason = ? WHERE id = ?");
    $updateStmt->bind_param("si", $reason, $userID);
    $updateStmt->execute();
    
    // Notify students about cancelled pending bookings
    if ($cancelledCount > 0) {
        $getStudents = $conn->prepare("
            SELECT DISTINCT student_id FROM bookings 
            WHERE tutor_id = ? AND status = 'cancelled' AND cancellation_reason = 'Tutor account deactivated'
        ");
        $getStudents->bind_param("i", $userID);
        $getStudents->execute();
        $students = $getStudents->get_result();
        
        while ($student = $students->fetch_assoc()) {
            $notifStmt = $conn->prepare("
                INSERT INTO notifications (user_id, type, title, message, created_at) 
                VALUES (?, 'booking_cancelled', 'Booking Cancelled', 
                'Your pending booking with {$tutor['fullname']} has been cancelled because the tutor deactivated their account.', NOW())
            ");
            $notifStmt->bind_param("i", $student['student_id']);
            $notifStmt->execute();
        }
    }
    
    $emailBody = "
    <div style='font-family: Arial, sans-serif; max-width: 500px; margin: auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 10px;'>
        <h2 style='color: #E75A9B;'>Account Deactivated</h2>
        <p>Dear {$tutor['fullname']},</p>
        <p>Your Kyoshi tutor account has been deactivated as requested.</p>
        <p>Reason provided: " . nl2br(htmlspecialchars($reason)) . "</p>
        <p>" . ($cancelledCount > 0 ? "Your $cancelledCount pending booking(s) have been automatically cancelled." : "You had no pending bookings.") . "</p>
        <p>You can reactivate your account by contacting support.</p>
        <hr>
        <p style='font-size: 12px; color: #666;'>Thank you for teaching with Kyoshi!</p>
    </div>";
    sendEmail($tutor['email'], 'Your Tutor Account Has Been Deactivated', $emailBody);
    
    $_SESSION['success_message'] = 'Account deactivated successfully. You will be logged out.';
    header("Location: logout.php");
    exit();
}
}

$totalSessions = $stats_result['total_sessions'] ?? 0;
$completedCount = $stats_result['completed_sessions'] ?? 0;

function e($value) {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile - Kyoshi Tutor</title>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<!-- SweetAlert2 for better modals -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    font-family: 'Poppins', sans-serif;
    background: url('../assets/img/background2.png') no-repeat center top;
    background-size: cover;
    min-height: 100vh;
    position: relative;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: rgba(255, 255, 255, 0.25);
    z-index: -1;
}

.topbar {
    width: 100%;
    background: rgba(254, 214, 206, 0.92);
    backdrop-filter: blur(12px);
    position: sticky;
    top: 0;
    z-index: 999;
    box-shadow: 0 2px 20px rgba(0, 0, 0, 0.08);
    border-bottom: 1px solid rgba(255, 255, 255, 0.3);
}

.container { width: min(1400px, 94%); margin: auto; }
.nav { display: flex; justify-content: space-between; align-items: center; gap: 32px; min-height: 70px; }

.brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
    flex-shrink: 0;
}
.brand img { width: 42px; height: 42px; object-fit: contain; }
.brand strong { display: block; color: #1d3156; font-size: 20px; line-height: 1.2; }
.brand span { color: #496894; font-size: 11px; }

.nav-links { display: flex; gap: 28px; align-items: center; flex-wrap: wrap; }
.nav-links a {
    text-decoration: none;
    color: #1d3156;
    font-size: 14px;
    font-weight: 600;
    position: relative;
    transition: 0.25s;
    padding: 6px 0;
}
.nav-links a:hover, .nav-links a.active { color: #496894; }
.nav-links a::after {
    content: '';
    position: absolute;
    left: 0;
    bottom: -6px;
    width: 0%;
    height: 3px;
    background: #496894;
    transition: 0.25s;
    border-radius: 10px;
}
.nav-links a:hover::after, .nav-links .active::after { width: 100%; }

.profile {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.1);
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 6px 14px 6px 8px;
    border-radius: 40px;
    cursor: pointer;
    color: black;
    transition: 0.25s;
    position: relative;
}
.profile:hover { background: rgba(255, 255, 255, 0.2); }
.profile img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(255, 255, 255, 0.3); }
.profile span { font-size: 13px; font-weight: 500; }

.dropdown {
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 220px;
    background: white;
    border-radius: 16px;
    overflow: hidden;
    display: none;
    border: 1px solid #e2edf7;
    box-shadow: 0 15px 35px rgba(0, 0, 0, 0.15);
    z-index: 1000;
}
.dropdown a {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 18px;
    text-decoration: none;
    color: #1e293b;
    font-size: 13px;
    font-weight: 500;
}
.dropdown a:hover { background: #f8fafc; }
.dropdown hr { border: none; border-top: 1px solid #ecf3f9; }

.main { width: min(1280px, 92%); margin: 32px auto 48px; }

/* Page Header */
.page-header-centered { text-align: center; margin-bottom: 28px; }
.page-header-centered h1 { font-size: 28px; font-weight: 800; color: #1d3156; letter-spacing: -0.5px; }
.page-header-centered p { color: #1e293b; margin-top: 6px; font-size: 13px; font-weight: 500; }

.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: white;
    color: #1d3156;
    padding: 10px 20px;
    border-radius: 40px;
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    border: 1px solid #e2e8f0;
    transition: 0.25s;
}
.back-btn:hover { background: #b8d0e9; border-color: #6b9cd7; transform: translateX(-3px); }

/* Profile Layout */
.profile-layout {
    display: grid;
    grid-template-columns: 320px 1fr;
    gap: 24px;
    align-items: start;
    margin-top: 20px;
}

/* Left Sidebar */
.profile-sidebar {
    background: white;
    border-radius: 24px;
    padding: 28px;
    text-align: center;
    border: 1px solid #eef2f7;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
}

.avatar-wrap {
    position: relative;
    width: 120px;
    height: 120px;
    margin: 0 auto 18px;
}
.avatar-wrap img {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid white;
    box-shadow: 0 12px 28px rgba(201,79,134,.2);
}
.avatar-edit {
    position: absolute;
    bottom: 4px;
    right: 4px;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #E75A9B, #F28AB2);
    border: 2px solid white;
    color: #fff;
    cursor: pointer;
    display: grid;
    place-items: center;
    font-size: 14px;
}

.sidebar-name { font-size: 22px; font-weight: 900; margin: 0; }
.sidebar-role {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    background: rgba(242,138,178,.18);
    color: #C94F86;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    margin: 8px 0 0;
}

.sidebar-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 22px;
    text-align: left;
}
.s-stat {
    background: rgba(255,241,246,.7);
    border-radius: 18px;
    padding: 14px;
    font-size: 1px;
}
.s-stat span { display: block; font-size: 11px; color: #7B6178; font-weight: 700; }
.s-stat strong { display: block; font-size: 20px; font-weight: 900; margin-top: 4px; }

.sidebar-btns {
    display: flex;
    flex-direction: column;
    gap: 10px;
    margin-top: 22px;
}

.btn-primary {
    background: linear-gradient(135deg, #E75A9B, #F28AB2);
    color: #fff;
    padding: 13px 20px;
    border-radius: 999px;
    border: none;
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    width: 100%;
    transition: 0.2s;
}
.btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(231,90,155,0.3); }

.btn-outline {
    background: rgba(255,255,255,.84);
    color: #7A3D65;
    padding: 13px 20px;
    border-radius: 999px;
    border: 1px solid rgba(46,42,59,.10);
    font-size: 13px;
    font-weight: 900;
    cursor: pointer;
    width: 100%;
    transition: 0.2s;
}
.btn-outline:hover { transform: translateY(-1px); background: #fff; }

/* Right Content */
.glass-card {
    background: rgba(255,255,255,0.95);
    backdrop-filter: blur(5px);
    border-radius: 24px;
    border: 1px solid rgba(255,255,255,0.3);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    overflow: hidden;
}

.form-panel { padding: 28px 32px; }
.form-panel h3 { margin: 0 0 6px; font-size: 22px; }
.form-panel .sub { color: #7B6178; font-size: 14px; margin: 0 0 24px; }

.form-group { margin-bottom: 20px; }
.form-group label {
    font-size: 13px;
    font-weight: 900;
    color: #6D4964;
    display: block;
    margin-bottom: 6px;
}
.form-group input, .form-group textarea, .form-group select {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid rgba(46,42,59,.12);
    border-radius: 14px;
    font-size: 14px;
    background: rgba(255,255,255,.88);
    font-family: 'Poppins', sans-serif;
}
.form-group input:focus, .form-group textarea:focus {
    border-color: #E75A9B;
    outline: none;
    box-shadow: 0 0 0 3px rgba(231,90,155,0.1);
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.section-divider {
    height: 1px;
    background: rgba(46,42,59,.08);
    margin: 24px 0;
}

/* Info Tags */
.info-tags { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px; }
.info-tag {
    background: #f8fafc;
    padding: 8px 16px;
    border-radius: 30px;
    font-size: 13px;
    color: #1d3156;
    border: 1px solid #e2e8f0;
    display: inline-block;
}
.info-tag i { color: #E75A9B; margin-right: 6px; }

.language-tag {
    background: #e0f2fe;
    color: #0284c7;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    display: inline-block;
    margin: 4px;
}
.mode-tag {
    background: #e8f5e9;
    color: #2e7d32;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    display: inline-block;
    margin: 4px;
}
.location-tag {
    background: #fff3e0;
    color: #f59e0b;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    display: inline-block;
    margin: 4px;
}
.availability-item {
    background: #f3e8ff;
    color: #7c3aed;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 12px;
    display: inline-block;
    margin: 4px;
}
.edit-link {
    margin-left: 8px;
    font-size: 11px;
    background: #e2e8f0;
    padding: 2px 8px;
    border-radius: 20px;
    text-decoration: none;
    color: #4a5568;
}
.edit-link:hover { background: #cbd5e1; }

.certificate-item {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 12px 15px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}
.cert-status-pending {
    background: #fef3c7;
    color: #f59e0b;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.cert-status-approved {
    background: #d4edda;
    color: #28a745;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}
.cert-status-rejected {
    background: #fee2e2;
    color: #dc2626;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.danger-zone {
    border: 2px solid #dc3545;
    background: #fff5f5;
    margin-top: 24px;
}

/* Tabs */
.tabs {
    display: flex;
    gap: 6px;
    background: rgba(255,255,255,0.58);
    border: 1px solid rgba(242,138,178,.18);
    border-radius: 999px;
    padding: 7px;
    width: max-content;
    margin-bottom: 24px;
}
.tab {
    padding: 10px 18px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 900;
    color: #6D4964;
    cursor: pointer;
    background: transparent;
    border: none;
    transition: 0.18s ease;
}
.tab.active, .tab:hover {
    background: linear-gradient(135deg, #E75A9B, #F28AB2);
    color: #fff;
    box-shadow: 0 8px 18px rgba(231,90,155,.28);
}

/* Edit Actions */
.edit-actions {
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid rgba(231,90,155,.12);
}

/* Modal */
.modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 2000;
    display: flex;
    align-items: center;
    justify-content: center;
}
.modal-container {
    background: white;
    border-radius: 24px;
    max-width: 650px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header h2 {
    color: #1d3156;
    margin: 0;
    font-size: 20px;
}
.modal-close {
    background: none;
    border: none;
    font-size: 28px;
    cursor: pointer;
    color: #94a3b8;
}
.modal-body {
    padding: 24px;
}
.modal-footer {
    padding: 16px 24px;
    border-top: 1px solid #e2e8f0;
    display: flex;
    justify-content: flex-end;
    gap: 12px;
}

.disabled {
    opacity: 0.75;
    pointer-events: none;
}
.disabled input, .disabled textarea, .disabled select {
    background: #f8f8f8;
    color: #888;
}

/* Toast */
.toast {
    position: fixed;
    left: 50%;
    bottom: 28px;
    transform: translate(-50%, 18px);
    opacity: 0;
    background: #28a745;
    color: white;
    padding: 12px 22px;
    border-radius: 999px;
    z-index: 500;
    transition: 0.2s ease;
    font-size: 13px;
    font-weight: 500;
}
.toast.show { opacity: 1; transform: translate(-50%, 0); }
.toast.error { background: #dc2626; }

@media (max-width: 900px) {
    .profile-layout { grid-template-columns: 1fr; }
}
@media (max-width: 768px) {
    .form-row { grid-template-columns: 1fr; }
    .nav-links { gap: 14px; }
    .nav-links a { font-size: 12px; }
    .tab { padding: 8px 12px; font-size: 12px; }
}

/* Ensure modal doesn't block the toast */
.modal-overlay {
    z-index: 2000;
}
.toast {
    z-index: 9999 !important;
}

.cert-status-pending {
    background: #fef3c7;
    color: #f59e0b;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.cert-status-approved {
    background: #d4edda;
    color: #28a745;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.cert-status-rejected {
    background: #fee2e2;
    color: #dc2626;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
</style>
</head>

<body>

<header class="topbar">
    <div class="container">
        <nav class="nav">
            <a href="tutor_dashboard.php" class="brand">
                <img src="<?= e($assetBase) ?>/logo.png" alt="Kyoshi">
                <div><strong>Kyoshi</strong><span>Teacher Space</span></div>
            </a>
            <div class="nav-links">
                <a href="tutor_dashboard.php">Dashboard</a>
                <a href="booking_requests.php">My Bookings</a>
                <a href="material_overview.php">My Materials</a>
                <a href="assignment_overview.php">My Assignments</a>
                <a href="view_session_reports.php">My Reports</a>
            </div>
            <div style="position:relative;">
                <button class="profile" onclick="toggleDropdown()">
                    <img src="<?= e($profilePic) ?>">
                    <span><?= e($displayName) ?></span>
                    <i class="bi bi-chevron-down"></i>
                </button>
                <div class="dropdown" id="profileDropdown">
                    <a href="teacher_profile.php"><i class="bi bi-person-circle"></i> My Profile</a>
                    <a href="earnings.php"><i class="bi bi-wallet2"></i> My Earnings</a>
                    <hr>
                    <a href="logout.php" style="color:#dc2626;"><i class="bi bi-box-arrow-right"></i> Logout</a>
                </div>
            </div>
        </nav>
    </div>
</header>

<div class="main">
    <!-- Header with Back Button -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 32px; position: relative;">
        <a href="tutor_dashboard.php" class="back-btn">
            <i class="bi bi-arrow-left"></i> Back
        </a>
        <div style="position: absolute; left: 50%; transform: translateX(-50%); text-align: center;">
            <h1 style="font-size: 24px; font-weight: 800; color: #1d3156; margin: 0;"><i class="bi bi-person-circle"></i> My Profile</h1>
            <p style="color: #1e293b; margin: 4px 0 0; font-size: 12px;">Manage your account and preferences</p>
        </div>
        <div style="width: 100px;"></div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
        <button class="tab active" onclick="switchTab('profile')"><i class="bi bi-person"></i> Profile</button>
        <button class="tab" onclick="switchTab('security')"><i class="bi bi-shield-lock"></i> Security</button>
        <button class="tab" onclick="switchTab('certificates')"><i class="bi bi-file-earmark-text"></i> Certificates</button>
        <button class="tab" onclick="switchTab('bank')"><i class="bi bi-bank2"></i> Bank Account</button>
    </div>

    <!-- Profile Tab -->
    <div id="tabProfile">
        <div class="profile-layout">
            <!-- Left Sidebar -->
            <aside class="profile-sidebar">
                <div class="avatar-wrap">
    <img src="<?= e($profilePic) ?>" id="previewImg" alt="Profile">
    <button type="button" class="avatar-edit" id="directUploadBtn" title="Change photo" style="background: linear-gradient(135deg, #E75A9B, #F28AB2); border: 2px solid white; border-radius: 50%; width: 34px; height: 34px; display: grid; place-items: center; cursor: pointer;">
        <i class="bi bi-camera" style="color: white; font-size: 14px;"></i>
    </button>
</div>

<!-- Hidden file input for direct upload -->
<input type="file" id="directFileInput" accept="image/jpeg,image/png,image/jpg,image/gif" style="display: none;">
                <h2 class="sidebar-name"><?= e($displayName) ?></h2>
                <span class="sidebar-role"><i class="bi bi-mortarboard-fill"></i> Tutor</span>
                <div class="sidebar-stats">
                    <div class="s-stat"><span>Total Sessions</span><strong><?= $totalSessions ?></strong></div>
                    <div class="s-stat"><span>Completed</span><strong><?= $completedCount ?></strong></div>
                    <div class="s-stat"><span>Avg Rating</span><strong><?= number_format($stats_result['avg_rating'] ?? 0, 1) ?>⭐</strong></div>
                    <div class="s-stat"><span>Hourly Rate</span><strong>RM <?= number_format($tutor['rate'] ?? 0, 2) ?></strong></div>
                </div>
                <div class="sidebar-btns">
                    <button class="btn-primary" onclick="openEditProfileModal()"><i class="bi bi-pencil"></i> Edit Profile</button>
                    <button type="button" class="btn-primary" style="background: #dc2626; width: auto; padding: 12px 30px;" onclick="openDeactivateModal()">
                    <i class="bi bi-box-arrow-right"></i> Deactivate Account
                </button>
                </div>
            </aside>

            <!-- Right Content - Display Info -->
            <div class="glass-card">
                <div class="form-panel">
                    <h3>Profile Information</h3>
                    <p class="sub">Your teaching profile details</p>
                    
                    <div class="info-tags" style="margin-bottom: 20px;">
                        <span class="info-tag"><i class="bi bi-envelope"></i> <?= e($tutor['email']) ?></span>
                        <span class="info-tag"><i class="bi bi-telephone"></i> <?= e($tutor['phone'] ?? 'No phone') ?></span>
                        <span class="info-tag"><i class="bi bi-briefcase"></i> <?= $tutor['experience'] ?? 0 ?> years exp</span>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <h4 style="margin-bottom: 12px;">
                        <i class="bi bi-translate"></i> Languages & Proficiency
                    </h4>
                    <div>
                        <?php foreach ($tutorLanguages as $lang): ?>
                            <span class="language-tag"><?= e($lang['language']) ?> - <?= ucfirst($lang['proficiency_level']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <h4 style="margin-bottom: 12px;"><i class="bi bi-laptop"></i> Teaching Modes</h4>
                    <div>
                        <?php foreach ($teachingModes as $mode): ?>
                            <span class="mode-tag"><i class="bi bi-<?= $mode['mode'] === 'online' ? 'laptop' : 'people' ?>"></i> <?= ucfirst(str_replace('_', ' ', $mode['mode'])) ?></span>
                        <?php endforeach; ?>
                        <?php if ($teachingLocation && $teachingLocation['location']): ?>
                            <span class="location-tag"><i class="bi bi-geo-alt"></i> <?= e($teachingLocation['location']) ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <h4 style="margin-bottom: 12px;"><i class="bi bi-patch-check"></i> Qualifications</h4>
                    <div>
                        <?php if (!empty($tutor['qualifications'])): 
                            $quals = explode(' | ', $tutor['qualifications']);
                            foreach ($quals as $qual): ?>
                                <span class="info-tag"><?= e($qual) ?></span>
                            <?php endforeach;
                        else: ?>
                            <span class="info-tag" style="background: #fef3c7; color: #f59e0b;">No qualifications added</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <h4 style="margin-bottom: 12px;">
                        <i class="bi bi-calendar-week"></i> Availability Schedule
                        <a href="availability.php" class="edit-link" style="margin-left: 12px;"><i class="bi bi-pencil"></i> Edit</a>
                    </h4>
                    <div>
                        <?php if (!empty($availability)): ?>
                            <?php foreach ($availability as $slot): ?>
                                <span class="availability-item"><i class="bi bi-clock"></i> <?= e($slot['day_of_week']) ?>: <?= date('g:i A', strtotime($slot['start_time'])) ?> - <?= date('g:i A', strtotime($slot['end_time'])) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="info-tag">No availability set</span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($tutor['bio']): ?>
                    <div class="section-divider"></div>
                    <h4 style="margin-bottom: 12px;"><i class="bi bi-chat-text"></i> About Me</h4>
                    <p style="color: #475569; line-height: 1.6;"><?= nl2br(e($tutor['bio'])) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Danger Zone moved under Edit Profile but above other tabs content -->
    </div>

   <!-- Security Tab (Change Password) -->
<!-- Security Tab (Change Password) -->
<div id="tabSecurity" style="display: none;">
    <div class="glass-card">
        <div class="form-panel">
            <h3>Change Password</h3>
            <p class="sub">Choose a strong password to protect your account</p>
            <form method="POST" id="passwordForm" onsubmit="return validatePasswordForm()">
                <input type="hidden" name="action" value="change_password">
                
                <!-- Current Password -->
                <div class="form-group">
                    <label>Current Password</label>
                    <div class="password-wrapper" style="position: relative;">
                        <input type="password" name="current_password" id="currentPwd" class="form-control" required>
                        <span class="eye-icon" onclick="togglePasswordVisibility('currentPwd', this)" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); cursor: pointer; color: gray; font-size: 18px;">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                </div>
                
                <!-- New Password -->
                <div class="form-group">
                    <label>New Password</label>
                    <div class="password-wrapper" style="position: relative;">
                        <input type="password" name="new_password" id="newPwd" class="form-control" required>
                        <span class="eye-icon" onclick="togglePasswordVisibility('newPwd', this)" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); cursor: pointer; color: gray; font-size: 18px;">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                </div>
                
                <!-- Password strength indicator -->
                <div id="passwordStrength" style="margin-top: 5px; margin-bottom: 10px;">
                    <div style="display: flex; gap: 5px; margin-bottom: 5px;">
                        <div id="strengthBar1" style="height: 4px; flex: 1; background: #e2e8f0; border-radius: 2px;"></div>
                        <div id="strengthBar2" style="height: 4px; flex: 1; background: #e2e8f0; border-radius: 2px;"></div>
                        <div id="strengthBar3" style="height: 4px; flex: 1; background: #e2e8f0; border-radius: 2px;"></div>
                        <div id="strengthBar4" style="height: 4px; flex: 1; background: #e2e8f0; border-radius: 2px;"></div>
                    </div>
                    <span id="strengthText" style="font-size: 12px; color: #64748b;">Enter a password</span>
                </div>
                
                <div id="passwordRules" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; margin-bottom: 15px; display: none;">
                    <div style="font-size: 12px; margin-bottom: 5px; color: #64748b;">Password requirements:</div>
                    <div id="ruleLength" style="font-size: 11px; color: #94a3b8;"><i class="bi bi-circle"></i> At least 8 characters</div>
                    <div id="ruleUpper" style="font-size: 11px; color: #94a3b8;"><i class="bi bi-circle"></i> One uppercase letter (A-Z)</div>
                    <div id="ruleNumber" style="font-size: 11px; color: #94a3b8;"><i class="bi bi-circle"></i> One number (0-9)</div>
                    <div id="ruleSpecial" style="font-size: 11px; color: #94a3b8;"><i class="bi bi-circle"></i> One special character (!@#$%^&*)</div>
                </div>
                
                <!-- Confirm Password -->
                <div class="form-group">
                    <label>Confirm New Password</label>
                    <div class="password-wrapper" style="position: relative;">
                        <input type="password" name="confirm_password" id="confirmPwd" class="form-control" required>
                        <span class="eye-icon" onclick="togglePasswordVisibility('confirmPwd', this)" style="position: absolute; right: 14px; top: 50%; transform: translateY(-50%); cursor: pointer; color: gray; font-size: 18px;">
                            <i class="bi bi-eye"></i>
                        </span>
                    </div>
                    <span id="matchMsg" style="font-size: 12px; margin-top: 5px; display: block;"></span>
                </div>
                
                <button type="submit" class="btn-primary" style="width: auto; padding: 12px 30px;"><i class="bi bi-shield-check"></i> Update Password</button>
            </form>
        </div>
    </div>
</div>


<div id="tabCertificates" style="display: none;">
    <div class="glass-card">
        <div class="form-panel">
            <div style="background: #e0f2fe; border-radius: 12px; padding: 15px; margin-bottom: 20px; border-left: 4px solid #0284c7;">
                <h4 style="color: #0284c7; margin-bottom: 8px;"><i class="bi bi-info-circle-fill"></i> How to Add Your Qualifications?</h4>
                <p style="font-size: 13px; color: #475569; margin-bottom: 5px;">1. Upload your certificate (PDF, JPG, or PNG format)</p>
                <p style="font-size: 13px; color: #475569; margin-bottom: 5px;">2. Our admin will verify your certificate</p>
                <p style="font-size: 13px; color: #475569; margin-bottom: 5px;">3. Once approved, your qualification will appear in your profile</p>
                <p style="font-size: 13px; color: #dc2626; margin-top: 8px;">Note: Only verified qualifications are shown to students</p>
            </div>
            
            <h3><i class="bi bi-upload"></i> Upload New Certificate</h3>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload_certificate">
                <div class="form-group">
                    <label>Certificate/Qualification Name</label>
                    <input type="text" name="certificate_name" placeholder="e.g., HSK Level 5 Certificate, TESOL Certification, Bachelor's Degree" required>
                    <small>Give your certificate a clear name</small>
                </div>
                <div class="form-group">
                    <label>Certificate File</label>
                    <input type="file" name="certificate_file" accept=".pdf,.jpg,.jpeg,.png" required>
                    <small>PDF, JPG, PNG files only (Max 5MB)</small>
                </div>
                <button type="submit" class="btn-primary" style="width: auto; padding: 12px 30px;"><i class="bi bi-upload"></i> Upload Certificate</button>
            </form>
            
            <?php if (!empty($certificates)): ?>
            <div class="section-divider"></div>
            <h3><i class="bi bi-file-earmark-text"></i> My Certificates & Qualifications</h3>
            <p class="sub" style="font-size: 12px;">Certificates with "Approved" status will be shown on your profile</p>
            
            <?php foreach ($certificates as $cert): ?>
            <div class="certificate-item">
                <div>
                    <i class="bi bi-file-earmark-pdf"></i>
                    <strong><?= e($cert['certificate_name']) ?></strong>
                    <div style="font-size: 11px; color: #64748b;">Uploaded: <?= date('d M Y', strtotime($cert['uploaded_at'])) ?></div>
                </div>
                <div>
                    <?php if ($cert['status'] == 'pending'): ?>
                        <span class="cert-status-pending"><i class="bi bi-clock-history"></i> Pending Verification</span>
                    <?php elseif ($cert['status'] == 'approved'): ?>
                        <span class="cert-status-approved"><i class="bi bi-check-circle-fill"></i> Approved ✓</span>
                    <?php else: ?>
                        <span class="cert-status-rejected"><i class="bi bi-x-circle-fill"></i> Rejected</span>
                    <?php endif; ?>
                    
                    <?php if ($cert['file_path']): ?>
                        <a href="../uploads/certificates/<?= e($cert['file_path']) ?>" target="_blank" class="btn-outline" style="padding: 5px 12px; font-size: 11px; display: inline-block; width: auto;">View</a>
                    <?php endif; ?>
                    
                    <?php if ($cert['status'] == 'pending'): ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="action" value="delete_certificate">
                            <input type="hidden" name="cert_id" value="<?= $cert['id'] ?>">
                            <button type="submit" class="btn-outline" style="background: #fee2e2; color: #dc2626; padding: 5px 12px; font-size: 11px; width: auto;" onclick="return confirm('Delete this certificate?')">Delete</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            
            <div style="background: #fef3c7; border-radius: 12px; padding: 12px; margin-top: 15px;">
                <p style="font-size: 12px; color: #92400e; margin: 0;"><i class="bi bi-question-circle-fill"></i> <strong>What happens next?</strong></p>
                <p style="font-size: 12px; color: #92400e; margin: 5px 0 0;">After uploading, admin will review your certificate. Once approved, it will appear in the "Qualifications" section of your profile. This helps build trust with students!</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div><!-- Bank Account Tab - MULTIPLE ACCOUNTS VERSION -->
<div id="tabBank" style="display: none;">
    <div class="glass-card">
        <div class="form-panel">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <h3><i class="bi bi-bank2"></i> Bank Account Details</h3>
                <?php if (count($bankAccounts) < 3): ?>
                    <button class="btn-primary" onclick="openBankModal()" style="width: auto; padding: 8px 20px;">
                        <i class="bi bi-plus-circle"></i> Add Account
                    </button>
                <?php endif; ?>
            </div>
            <p class="sub">Your bank details are required to receive payouts (Max 3 accounts)</p>
            
            <!-- Security notice -->
            <div style="background: #e0f2fe; border-radius: 12px; padding: 12px 16px; margin-bottom: 24px; display: flex; align-items: center; gap: 12px;">
                <i class="bi bi-shield-lock-fill" style="color: #0284c7; font-size: 20px;"></i>
                <div style="font-size: 12px; color: #075985;">
                    <strong>Securely stored</strong> — Your bank details are encrypted and only used for payout requests.
                </div>
            </div>
            
            <?php if (empty($bankAccounts)): ?>
                <!-- No bank accounts -->
                <div class="empty-state" style="text-align: center; padding: 40px 20px; background: #f8fafc; border-radius: 16px;">
                    <i class="bi bi-bank2" style="font-size: 48px; color: #cbd5e1; display: block; margin-bottom: 15px;"></i>
                    <p style="color: #64748b; margin-bottom: 20px;">No bank account added yet. Add up to 3 bank accounts.</p>
                </div>
            <?php else: ?>
                <!-- Show all bank accounts -->
                <?php foreach ($bankAccounts as $index => $account): ?>
                    <div style="background: #f8fafc; border-radius: 16px; padding: 16px 20px; margin-bottom: 16px; border: 1px solid <?= $account['is_default'] ? '#28a745' : '#e2e8f0' ?>;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <?php if ($account['is_default']): ?>
                                    <span style="background: #28a745; color: white; padding: 2px 10px; border-radius: 20px; font-size: 10px;">DEFAULT</span>
                                <?php endif; ?>
                                <span style="font-weight: 600;">Account <?= $index + 1 ?></span>
                            </div>
                            <div style="display: flex; gap: 8px;">
                                <?php if (!$account['is_default']): ?>
                                    <button class="btn-outline" onclick="setDefaultBank(<?= $account['id'] ?>)" style="padding: 4px 12px; font-size: 11px; width: auto;">
                                        Set as Default
                                    </button>
                                <?php endif; ?>
                                <button class="btn-outline" onclick="editBankAccount(<?= $account['id'] ?>)" style="padding: 4px 12px; font-size: 11px; width: auto;">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>
                                <button class="btn-outline" onclick="deleteBankAccount(<?= $account['id'] ?>)" style="padding: 4px 12px; font-size: 11px; width: auto; background: #fee2e2; color: #dc2626; border-color: #fecaca;">
                                    <i class="bi bi-trash"></i> Delete
                                </button>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 130px 1fr; gap: 10px; font-size: 13px;">
                            <div style="color: #475569;">Bank Name:</div>
                            <div style="font-weight: 500;"><?= e($account['bank_name']) ?></div>
                            
                            <div style="color: #475569;">Account Number:</div>
                            <div style="font-weight: 500;">****<?= substr(e($account['bank_account_number']), -4) ?></div>
                            
                            <div style="color: #475569;">Account Holder:</div>
                            <div style="font-weight: 500;"><?= e($account['bank_account_name']) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                
                <?php if (count($bankAccounts) < 3): ?>
                    <button class="btn-outline" onclick="openBankModal()" style="width: 100%; padding: 12px; margin-top: 8px;">
                        <i class="bi bi-plus-lg"></i> Add Another Bank Account (<?= count($bankAccounts) ?>/3)
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>
<!-- Edit Profile Modal -->
<div id="editProfileModal" style="display: none;">
    <div class="modal-overlay" onclick="closeEditProfileModal(event)">
        <div class="modal-container" style="max-width: 750px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2><i class="bi bi-pencil-square"></i> Edit Profile</h2>
                <button class="modal-close" onclick="closeEditProfileModal()">&times;</button>
            </div>
            <form method="POST" id="profileForm">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-body">
                    <!-- Basic Info -->
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="fullname" value="<?= e($tutor['fullname']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="tel" name="phone" value="<?= e($tutor['phone'] ?? '') ?>" placeholder="Enter your phone number">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Years of Experience</label>
                            <input type="number" name="experience" value="<?= $tutor['experience'] ?? 0 ?>" min="0" step="1">
                        </div>
                        <div class="form-group">
                            <label>Hourly Rate (RM)</label>
                            <input type="number" name="rate" value="<?= $tutor['rate'] ?? 0 ?>" min="0" step="5" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Bio / About Me</label>
                        <textarea name="bio" rows="4"><?= e($tutor['bio'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="section-divider"></div>
                    
                    <!-- Languages & Proficiency -->
<h4><i class="bi bi-translate"></i> Languages I Teach</h4>
<p class="sub" style="font-size: 12px;">Add languages you can teach and your proficiency level</p>

<div id="languagesContainer">
    <?php if (empty($tutorLanguages)): ?>
    <div class="language-item-card" style="background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 12px; border: 1px solid #e2e8f0;">
        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;">
            <div style="flex: 2; min-width: 150px;">
                <select name="languages[]" class="form-control" style="width: 100%;">
                    <option value="">Select Language</option>
                    <option value="English">🇬🇧 English</option>
                    <option value="Japanese">🇯🇵 Japanese</option>
                    <option value="Mandarin">🇨🇳 Mandarin</option>
                    <option value="Malay">🇲🇾 Malay</option>
                    <option value="Korean">🇰🇷 Korean</option>
                </select>
            </div>
            <div style="flex: 2; min-width: 180px;">
                <select name="proficiency[]" class="form-control" style="width: 100%;">
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                </select>
            </div>
            <div style="flex: 0 0 auto;">
                <button type="button" class="btn-outline" onclick="removeLanguage(this)" style="width: auto; padding: 10px 18px; background: #fee2e2; color: #dc2626; border-color: #fecaca;">
                    <i class="bi bi-trash"></i> Remove
                </button>
            </div>
        </div>
    </div>
    <?php else: ?>
        <?php foreach ($tutorLanguages as $index => $lang): ?>
        <div class="language-item-card" style="background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 12px; border: 1px solid #e2e8f0;">
            <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;">
                <div style="flex: 2; min-width: 150px;">
                    <select name="languages[]" class="form-control" style="width: 100%;">
                        <option value="">Select Language</option>
                        <option value="English" <?= $lang['language'] == 'English' ? 'selected' : '' ?>>🇬🇧 English</option>
                        <option value="Japanese" <?= $lang['language'] == 'Japanese' ? 'selected' : '' ?>>🇯🇵 Japanese</option>
                        <option value="Mandarin" <?= $lang['language'] == 'Mandarin' ? 'selected' : '' ?>>🇨🇳 Mandarin</option>
                        <option value="Malay" <?= $lang['language'] == 'Malay' ? 'selected' : '' ?>>🇲🇾 Malay</option>
                        <option value="Korean" <?= $lang['language'] == 'Korean' ? 'selected' : '' ?>>🇰🇷 Korean</option>
                    </select>
                </div>
                <div style="flex: 2; min-width: 180px;">
                    <select name="proficiency[]" class="form-control" style="width: 100%;">
                        <option value="beginner" <?= $lang['proficiency_level'] == 'beginner' ? 'selected' : '' ?>>Beginner </option>
                        <option value="intermediate" <?= $lang['proficiency_level'] == 'intermediate' ? 'selected' : '' ?>>Intermediate </option>
                        <option value="advanced" <?= $lang['proficiency_level'] == 'advanced' ? 'selected' : '' ?>>Advanced </option>
                        <option value="master" <?= $lang['proficiency_level'] == 'master' ? 'selected' : '' ?>>Master </option>
                    </select>
                </div>
                <div style="flex: 0 0 auto;">
                    <button type="button" class="btn-outline" onclick="removeLanguage(this)" style="width: auto; padding: 10px 18px; background: #fee2e2; color: #dc2626; border-color: #fecaca;">
                        <i class="bi bi-trash"></i> Remove
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div style="text-align: center; margin-top: 15px;">
    <button type="button" class="btn-outline" onclick="addLanguage()" style="width: auto; padding: 10px 30px; background: #e0f2fe; border-color: #38bdf8; color: #0284c7;">
        <i class="bi bi-plus-lg"></i> Add Another Language
    </button>
</div>
                    
                    <div class="section-divider"></div>
                    
                    <!-- Teaching Modes & Location -->
<h4>Teaching Modes & Location</h4><br>
<div style="margin-bottom: 15px;">
    <label style="display: block; margin-bottom: 8px;">
        <input type="checkbox" name="teaching_modes[]" value="online" <?= in_array('online', array_column($teachingModes, 'mode')) ? 'checked' : '' ?>> 
        <i class="bi bi-laptop"></i> Online 
    </label>
    <label style="display: block; margin-bottom: 8px;">
        <input type="checkbox" name="teaching_modes[]" value="face_to_face" <?= in_array('face_to_face', array_column($teachingModes, 'mode')) ? 'checked' : '' ?>> 
        <i class="bi bi-people"></i> Face to Face 
    </label>
</div>
<div class="form-group">
    <label>Teaching Location For F2F</label>
    <select name="teaching_location" class="form-control">
        <option value="">-- Select City --</option>
        <option value="Kuala Lumpur" <?= ($teachingLocation['location'] ?? '') == 'Kuala Lumpur' ? 'selected' : '' ?>>Kuala Lumpur</option>
        <option value="Penang" <?= ($teachingLocation['location'] ?? '') == 'Penang' ? 'selected' : '' ?>>Penang</option>
        <option value="Johor Bahru" <?= ($teachingLocation['location'] ?? '') == 'Johor Bahru' ? 'selected' : '' ?>>Johor Bahru</option>
        <option value="Kota Kinabalu" <?= ($teachingLocation['location'] ?? '') == 'Kota Kinabalu' ? 'selected' : '' ?>>Kota Kinabalu</option>
    </select>
    <small>Select your city above</small>
</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" style="width: auto; padding: 10px 24px;" onclick="closeEditProfileModal()">Cancel</button>
                    <button type="submit" class="btn-primary" style="width: auto; padding: 10px 24px;"><i class="bi bi-check2"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Deactivate Account Modal -->
<div id="deactivateModal" style="display: none;">
    <div class="modal-overlay" onclick="closeDeactivateModal(event)">
        <div class="modal-container" style="max-width: 750px;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 style="color: #dc2626;"><i class="bi bi-exclamation-triangle-fill"></i> Deactivate Account</h2>
                <button class="modal-close" onclick="closeDeactivateModal()">&times;</button>
            </div>
            <form method="POST" id="deactivateForm">
                <input type="hidden" name="action" value="deactivate_account">
                <div class="modal-body">
                    <div id="activeWarning" style="display: none; background: #fee2e2; border-radius: 12px; padding: 15px; margin-bottom: 16px; border-left: 4px solid #dc2626;">
    <p style="font-size: 14px; color: #991b1b; margin: 0;">
        <strong>Cannot Deactivate Account Yet</strong>
    </p>
    <p id="activeMessage" style="font-size: 13px; color: #991b1b; margin: 10px 0 0;"></p>
    <p style="font-size: 12px; color: #991b1b; margin: 10px 0 0;">
        <i class="bi bi-info-circle-fill"></i> <strong>What you can do:</strong><br>
        • Complete all your confirmed/accepted classes first<br>
        • Stop accepting new student requests until all classes are completed<br>
        • Contact the students to reschedule or cancel (with their agreement)<br>
        • Once all active classes are completed, you can deactivate your account
    </p>
</div>
                    
                    <div id="pendingWarning" style="display: none; background: #fef3c7; border-radius: 12px; padding: 12px; margin-bottom: 16px; border-left: 4px solid #f59e0b;">
                        <p style="font-size: 13px; color: #92400e; margin: 0;">
                            <i class="bi bi-clock-history"></i> <strong>Pending Bookings Will Be Cancelled</strong>
                        </p>
                        <p id="pendingMessage" style="font-size: 12px; color: #92400e; margin: 8px 0 0;"></p>
                        <p style="font-size: 12px; color: #92400e; margin: 8px 0 0;">
                            Any pending booking requests will be automatically cancelled.
                        </p>
                    </div>
                    
                    <div id="canDeactivateWarning" style="display: none; background: #d4edda; border-radius: 12px; padding: 12px; margin-bottom: 16px; border-left: 4px solid #28a745;">
                        <p style="font-size: 13px; color: #155724; margin: 0;">
                            <i class="bi bi-check-circle-fill"></i> <strong>You can deactivate your account</strong>
                        </p>
                        <p style="font-size: 12px; color: #155724; margin: 8px 0 0;">
                            • You have no confirmed/accepted classes ✅<br>
                            • Your profile will be hidden from student searches<br>
                            • You can reactivate by contacting support
                        </p>
                    </div><br>
                    <div id="pendingAcceptWarning" style="display: none; background: #fef3c7; border-radius: 12px; padding: 12px; margin-bottom: 16px; border-left: 4px solid #f59e0b;">
    <p style="font-size: 13px; color: #92400e; margin: 0;">
        <i class="bi bi-clock-history"></i> <strong>You still have pending booking requests!</strong>
    </p>
    <p style="font-size: 12px; color: #92400e; margin: 8px 0 0;">
        If you want to resign, please stop accepting new student requests. 
        Your pending requests will be automatically cancelled when you deactivate.
    </p>
</div>
                    
                    <p style="margin-bottom: 16px; color: #475569;">Are you sure you want to permanently deactivate your account?</p>
                    
                    <div class="form-group">
                        <label>Reason for deactivation (optional)</label>
                        <textarea name="deactivation_reason" id="deactivationReason" rows="3" placeholder="Please tell us why you're leaving..." style="width:100%; padding: 10px; border-radius: 12px; border: 1px solid #e2e8f0;"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-outline" style="width: auto; padding: 10px 20px;" onclick="closeDeactivateModal()">Cancel</button>
                    <button type="submit" id="confirmDeactivateBtn" class="btn-primary" style="background: #dc2626; width: auto; padding: 10px 20px;" disabled><i class="bi bi-box-arrow-right"></i> Confirm Deactivate</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div id="toast" class="toast"></div>

<script>
// Toggle dropdown
function toggleDropdown() {
    const dropdown = document.getElementById('profileDropdown');
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

window.addEventListener('click', function(e) {
    const dropdown = document.getElementById('profileDropdown');
    const button = document.querySelector('.profile');
    if (button && !button.contains(e.target) && dropdown && !dropdown.contains(e.target)) {
        dropdown.style.display = 'none';
    }
});

// Tab switching
function switchTab(tab) {
    document.getElementById('tabProfile').style.display = tab === 'profile' ? 'block' : 'none';
    document.getElementById('tabSecurity').style.display = tab === 'security' ? 'block' : 'none';
    document.getElementById('tabCertificates').style.display = tab === 'certificates' ? 'block' : 'none';
    document.getElementById('tabBank').style.display = tab === 'bank' ? 'block' : 'none';
    const tabs = document.querySelectorAll('.tab');
    tabs.forEach(btn => btn.classList.remove('active'));
    if (tab === 'profile') tabs[0].classList.add('active');
    else if (tab === 'security') tabs[1].classList.add('active');
    else if (tab === 'certificates') tabs[2].classList.add('active');
    else if (tab === 'bank') tabs[3].classList.add('active');
}

// Password strength check and validation
const newPwd = document.getElementById('newPwd');
const confirmPwd = document.getElementById('confirmPwd');
const matchMsg = document.getElementById('matchMsg');
const passwordRules = document.getElementById('passwordRules');
const ruleLength = document.getElementById('ruleLength');
const ruleUpper = document.getElementById('ruleUpper');
const ruleNumber = document.getElementById('ruleNumber');
const ruleSpecial = document.getElementById('ruleSpecial');
const strengthBar1 = document.getElementById('strengthBar1');
const strengthBar2 = document.getElementById('strengthBar2');
const strengthBar3 = document.getElementById('strengthBar3');
const strengthBar4 = document.getElementById('strengthBar4');
const strengthText = document.getElementById('strengthText');

function checkPasswordStrength(password) {
    let score = 0;
    let checks = {
        length: password.length >= 8,
        upper: /[A-Z]/.test(password),
        number: /[0-9]/.test(password),
        special: /[!@#$%^&*(),.?":{}|<>]/.test(password)
    };
    
    // Update rule displays
    if (password.length > 0) {
        passwordRules.style.display = 'block';
    } else {
        passwordRules.style.display = 'none';
    }
    
    // Update each rule with checkmark or circle
    if (checks.length) {
        ruleLength.innerHTML = checks.length ? '<i class="bi bi-check-circle-fill" style="color: #28a745;"></i> At least 8 characters' : '<i class="bi bi-circle"></i> At least 8 characters';
        ruleLength.style.color = checks.length ? '#28a745' : '#94a3b8';
        score += checks.length ? 1 : 0;
    }
    if (checks.upper) {
        ruleUpper.innerHTML = '<i class="bi bi-check-circle-fill" style="color: #28a745;"></i> One uppercase letter (A-Z)';
        ruleUpper.style.color = '#28a745';
        score++;
    } else {
        ruleUpper.innerHTML = '<i class="bi bi-circle"></i> One uppercase letter (A-Z)';
        ruleUpper.style.color = '#94a3b8';
    }
    if (checks.number) {
        ruleNumber.innerHTML = '<i class="bi bi-check-circle-fill" style="color: #28a745;"></i> One number (0-9)';
        ruleNumber.style.color = '#28a745';
        score++;
    } else {
        ruleNumber.innerHTML = '<i class="bi bi-circle"></i> One number (0-9)';
        ruleNumber.style.color = '#94a3b8';
    }
    if (checks.special) {
        ruleSpecial.innerHTML = '<i class="bi bi-check-circle-fill" style="color: #28a745;"></i> One special character (!@#$%^&*)';
        ruleSpecial.style.color = '#28a745';
        score++;
    } else {
        ruleSpecial.innerHTML = '<i class="bi bi-circle"></i> One special character (!@#$%^&*)';
        ruleSpecial.style.color = '#94a3b8';
    }
    
    // Update strength bars
    const colors = ['#dc2626', '#f59e0b', '#eab308', '#22c55e'];
    const texts = ['Weak', 'Fair', 'Good', 'Strong'];
    
    for (let i = 0; i < 4; i++) {
        if (i < score) {
            if (i === 0) document.getElementById('strengthBar1').style.background = colors[0];
            if (i === 1) document.getElementById('strengthBar2').style.background = colors[1];
            if (i === 2) document.getElementById('strengthBar3').style.background = colors[2];
            if (i === 3) document.getElementById('strengthBar4').style.background = colors[3];
        } else {
            if (i === 0) document.getElementById('strengthBar1').style.background = '#e2e8f0';
            if (i === 1) document.getElementById('strengthBar2').style.background = '#e2e8f0';
            if (i === 2) document.getElementById('strengthBar3').style.background = '#e2e8f0';
            if (i === 3) document.getElementById('strengthBar4').style.background = '#e2e8f0';
        }
    }
    
    strengthText.textContent = score > 0 ? `Password strength: ${texts[score-1]}` : 'Enter a password';
    strengthText.style.color = score > 0 ? colors[score-1] : '#64748b';
    
    // Check password match after strength check
    checkMatch();
    return checks;
}

function checkMatch() {
    const newPass = document.getElementById('newPwd').value;
    const confirmPass = document.getElementById('confirmPwd').value;
    
    if (confirmPass === '') {
        matchMsg.innerHTML = '';
        matchMsg.className = '';
        return false;
    }
    
    if (newPass === confirmPass) {
        matchMsg.innerHTML = '<i class="bi bi-check-circle-fill"></i> Passwords match!';
        matchMsg.style.color = '#28a745';
        return true;
    } else {
        matchMsg.innerHTML = '<i class="bi bi-x-circle-fill"></i> Passwords do not match';
        matchMsg.style.color = '#dc2626';
        return false;
    }
}

// Live password checking
if (newPwd) {
    newPwd.addEventListener('input', function() {
        checkPasswordStrength(this.value);
    });
    confirmPwd.addEventListener('input', checkMatch);
}

// Form validation before submit
function validatePasswordForm() {
    const currentPwd = document.getElementById('currentPwd').value;
    const newPass = document.getElementById('newPwd').value;
    const confirmPass = document.getElementById('confirmPwd').value;
    
    if (!currentPwd) {
        showToast('Please enter your current password', 'error');
        return false;
    }
    
    const checks = checkPasswordStrength(newPass);
    
    if (!checks.length || !checks.upper || !checks.number || !checks.special) {
        showToast('Please meet all password requirements', 'error');
        return false;
    }
    
    if (newPass !== confirmPass) {
        showToast('Passwords do not match', 'error');
        return false;
    }
    
    return true;
}

// Show toast message
function showToast(msg, type = 'success') {
    const toast = document.getElementById('toast');
    toast.textContent = msg;
    toast.className = 'toast show';
    if (type === 'error') toast.classList.add('error');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// Toggle password visibility
function togglePasswordVisibility(inputId, element) {
    const input = document.getElementById(inputId);
    const icon = element.querySelector('i');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}
// Preview photo from file input
function previewPhoto(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            document.getElementById('previewImg').src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function previewPhotoEdit(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewImg = document.getElementById('previewImg');
            if (previewImg) previewImg.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

// Open edit profile modal
function openEditProfileModal() {
    document.getElementById('editProfileModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeEditProfileModal(event) {
    if (event && event.target !== event.currentTarget && event.target !== document.getElementById('editProfileModal')) return;
    document.getElementById('editProfileModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Bank Account Functions
function openBankModal() {
    document.getElementById('modalTitle').innerHTML = '<i class="bi bi-bank2"></i> Add Bank Account';
    document.getElementById('bank_id').value = '0';
    document.getElementById('bank_name').value = '';
    document.getElementById('bank_account_number').value = '';
    document.getElementById('bank_account_name').value = '';
    document.getElementById('bankModal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function editBankAccount(bankId) {
    // First get the bank details from the existing data in the table
    // Since we already have bankAccounts array in PHP, we need to pass it to JS
    // Alternative: fetch via AJAX
    fetch(`get_bank_details.php?id=${bankId}`)
        .then(response => response.json())
        .then(data => {
            if (data) {
                document.getElementById('modalTitle').innerHTML = '<i class="bi bi-bank2"></i> Edit Bank Account';
                document.getElementById('bank_id').value = data.id;
                document.getElementById('bank_name').value = data.bank_name;
                document.getElementById('bank_account_number').value = data.bank_account_number;
                document.getElementById('bank_account_name').value = data.bank_account_name;
                document.getElementById('bankModal').style.display = 'block';
                document.body.style.overflow = 'hidden';
            }
        })
        .catch(error => {
            showToast('Error loading bank details', 'error');
        });
}

function deleteBankAccount(bankId) {
    Swal.fire({
        title: 'Remove Bank Account?',
        text: "This action cannot be undone!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, remove it'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="delete_bank"><input type="hidden" name="bank_id" value="${bankId}">`;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

function setDefaultBank(bankId) {
    Swal.fire({
        title: 'Set as Default?',
        text: "This bank account will be used for future payouts",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#28a745',
        cancelButtonColor: '#64748b',
        confirmButtonText: 'Yes, set as default'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `<input type="hidden" name="action" value="set_default_bank"><input type="hidden" name="bank_id" value="${bankId}">`;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// Also add SweetAlert2 for delete confirmation (you already have the CDN)
function closeBankModal(event) {
    if (event && event.target !== event.currentTarget && event.target !== document.getElementById('bankModal')) return;
    document.getElementById('bankModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Direct profile picture upload with page refresh
document.getElementById('directUploadBtn').addEventListener('click', function() {
    document.getElementById('directFileInput').click();
});

document.getElementById('directFileInput').addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        const file = this.files[0];
        
        // Check file size (max 5MB)
        if (file.size > 5 * 1024 * 1024) {
            showToast('File too large! Max 5MB', 'error');
            return;
        }
        
        // Check file type
        const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
        if (!allowedTypes.includes(file.type)) {
            showToast('Only JPG, PNG, GIF images allowed', 'error');
            return;
        }
        
        // Create FormData for AJAX upload
        const formData = new FormData();
        formData.append('action', 'upload_profile_pic');
        formData.append('profile_pic', file);
        
        // Show loading toast
        showToast('Uploading...', 'info');
        
        // Send AJAX request
        fetch(window.location.href, {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showToast('Profile picture updated! Refreshing...', 'success');
                // Refresh the page after 1 second
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            } else {
                showToast(data.error || 'Upload failed', 'error');
            }
        })
        .catch(error => {
            showToast('Error uploading image', 'error');
            console.error('Error:', error);
        });
        
        // Reset file input
        this.value = '';
    }
});
// Open deactivate modal with booking check
function openDeactivateModal() {
    fetch(window.location.href + '?check_upcoming=1')
        .then(response => response.json())
        .then(data => {
            const activeWarning = document.getElementById('activeWarning');
            const pendingWarning = document.getElementById('pendingWarning');
            const canDeactivateWarning = document.getElementById('canDeactivateWarning');
            const activeMessage = document.getElementById('activeMessage');
            const pendingMessage = document.getElementById('pendingMessage');
            const confirmBtn = document.getElementById('confirmDeactivateBtn');
            
            if (data.has_active) {
                // HAS ACTIVE/ACCEPTED/CONFIRMED CLASSES - CANNOT DEACTIVATE
                activeWarning.style.display = 'block';
                pendingWarning.style.display = 'none';
                canDeactivateWarning.style.display = 'none';
                activeMessage.innerHTML = `You have ${data.active_count} confirmed/accepted class(es) on ${data.active_dates}`;
                confirmBtn.disabled = true;
                confirmBtn.style.opacity = '0.5';
                confirmBtn.style.cursor = 'not-allowed';
                showToast('You have confirmed/accepted classes! Please complete them first.', 'error');
            } else {
                // NO ACTIVE CLASSES - CAN DEACTIVATE
                activeWarning.style.display = 'none';
                confirmBtn.disabled = false;
                confirmBtn.style.opacity = '1';
                confirmBtn.style.cursor = 'pointer';
                
                if (data.pending_count > 0) {
                    // Has pending bookings that will be cancelled
                    pendingWarning.style.display = 'block';
                    canDeactivateWarning.style.display = 'none';
                    pendingMessage.innerHTML = `You have ${data.pending_count} pending booking request(s) that will be automatically cancelled.`;
                } else {
                    // No bookings at all
                    pendingWarning.style.display = 'none';
                    canDeactivateWarning.style.display = 'block';
                }
            }
            
            document.getElementById('deactivateModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        })
        .catch(error => {
            console.error('Error checking bookings:', error);
            document.getElementById('deactivateModal').style.display = 'block';
            document.body.style.overflow = 'hidden';
        });
}

function closeDeactivateModal(event) {
    if (event && event.target !== event.currentTarget && event.target !== document.getElementById('deactivateModal')) return;
    document.getElementById('deactivateModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

// Add new language row
function addLanguage() {
    const container = document.getElementById('languagesContainer');
    const newRow = document.createElement('div');
    newRow.className = 'language-item-card';
    newRow.style.cssText = 'background: #f8fafc; border-radius: 12px; padding: 15px; margin-bottom: 12px; border: 1px solid #e2e8f0;';
    newRow.innerHTML = `
        <div style="display: flex; flex-wrap: wrap; gap: 12px; align-items: center; justify-content: space-between;">
            <div style="flex: 2; min-width: 150px;">
                <select name="languages[]" class="form-control" style="width: 100%;">
                    <option value="">Select Language</option>
                    <option value="English">🇬🇧 English</option>
                    <option value="Japanese">🇯🇵 Japanese</option>
                    <option value="Mandarin">🇨🇳 Mandarin</option>
                    <option value="Malay">🇲🇾 Malay</option>
                    <option value="Korean">🇰🇷 Korean</option>
                </select>
            </div>
            <div style="flex: 2; min-width: 180px;">
                <select name="proficiency[]" class="form-control" style="width: 100%;">
                    <option value="beginner">Beginner</option>
                    <option value="intermediate">Intermediate</option>
                    <option value="advanced">Advanced</option>
                    <option value="master">Master</option>
                </select>
            </div>
            <div style="flex: 0 0 auto;">
                <button type="button" class="btn-outline" onclick="removeLanguage(this)" style="width: auto; padding: 10px 18px; background: #fee2e2; color: #dc2626; border-color: #fecaca;">
                    <i class="bi bi-trash"></i> Remove
                </button>
            </div>
        </div>
    `;
    container.appendChild(newRow);
}

function removeLanguage(button) {
    const languageCard = button.closest('.language-item-card');
    if (languageCard) {
        languageCard.remove();
    }
}

// Validate form before submit
document.getElementById('profileForm').addEventListener('submit', function(e) {
    const languageSelects = document.querySelectorAll('select[name="languages[]"]');
    let hasEmptyLanguage = false;
    
    languageSelects.forEach(function(select) {
        if (select.value === '') {
            hasEmptyLanguage = true;
        }
    });
    
    if (hasEmptyLanguage) {
        e.preventDefault();
        showToast('Please select a language for all rows or remove empty rows before saving.', 'error');
        return false;
    }
    
    return true;
});

// Password match check
document.addEventListener('DOMContentLoaded', function() {
    const newPwd = document.getElementById('newPwd');
    const confirmPwd = document.getElementById('confirmPwd');
    const msgSpan = document.getElementById('pwdMatchMsg');
    
    if (newPwd && confirmPwd && msgSpan) {
        function checkMatch() {
            if (confirmPwd.value === '') {
                msgSpan.innerHTML = '';
            } else if (newPwd.value === confirmPwd.value) {
                msgSpan.innerHTML = '<i class="bi bi-check-circle"></i> Passwords match!';
                msgSpan.style.color = '#28a745';
            } else {
                msgSpan.innerHTML = '<i class="bi bi-x-circle"></i> Passwords do not match';
                msgSpan.style.color = '#dc2626';
            }
        }
        newPwd.addEventListener('input', checkMatch);
        confirmPwd.addEventListener('input', checkMatch);
    }
});

<?php if (isset($_SESSION['success_message'])): ?>
    showToast("<?= $_SESSION['success_message'] ?>");
    <?php unset($_SESSION['success_message']); ?>
<?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?>
    showToast("<?= $_SESSION['error_message'] ?>", 'error');
    <?php unset($_SESSION['error_message']); ?>
<?php endif; ?>
</script>
<div id="bankModal" style="display: none;">
    <div class="modal-overlay" onclick="closeBankModal(event)">
        <div class="modal-container" style="max-width: 750px; width: 90%;" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 id="modalTitle"><i class="bi bi-bank2"></i> Add Bank Account</h2>
                <button class="modal-close" onclick="closeBankModal()">&times;</button>
            </div>
            <form method="POST" id="bankForm">
                <input type="hidden" name="action" value="save_bank">
                <input type="hidden" name="bank_id" id="bank_id" value="0">
                <div class="modal-body" style="padding: 24px 28px;">
                    <p style="font-size: 13px; color: #64748b; margin-bottom: 20px;">
                        Your bank details are required to receive payouts. They are securely stored and only used for transferring your earnings.
                    </p>
                    
                    <div class="form-group">
                        <label>Bank Name</label>
                        <select name="bank_name" id="bank_name" required style="width: 100%; padding: 12px; border: 1px solid #cbd5e1; border-radius: 12px;">
                            <option value="">-- Select Bank --</option>
                            <option value="Maybank">Maybank</option>
                            <option value="CIMB Bank">CIMB Bank</option>
                            <option value="Public Bank">Public Bank</option>
                            <option value="RHB Bank">RHB Bank</option>
                            <option value="Hong Leong Bank">Hong Leong Bank</option>
                            <option value="AmBank">AmBank</option>
                            <option value="Bank Islam">Bank Islam</option>
                            <option value="Bank Rakyat">Bank Rakyat</option>
                            <option value="BSN">BSN</option>
                            <option value="OCBC Bank">OCBC Bank</option>
                            <option value="UOB Bank">UOB Bank</option>
                            <option value="Standard Chartered">Standard Chartered</option>
                            <option value="HSBC Bank">HSBC Bank</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Account Number</label>
                        <input type="text" name="bank_account_number" id="bank_account_number" placeholder="e.g., 112233445566" required pattern="[0-9]{8,20}">
                        <small style="font-size: 11px; color: #64748b;">Numbers only, 8-20 digits</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Account Holder Name</label>
                        <input type="text" name="bank_account_name" id="bank_account_name" placeholder="As shown on bank statement" required>
                        <small style="font-size: 11px; color: #64748b;">Must exactly match your bank account name</small>
                    </div>
                    
                    <div class="form-group" style="margin-top: 16px;">
                        <label style="display: flex; align-items: center; gap: 10px; font-weight: normal; cursor: pointer;">
                            <input type="checkbox" required style="width: 18px; height: 18px; margin: 0;"> 
                            <span style="font-size: 12px; color: #475569;">I confirm that the bank details above are correct and belong to me.</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer" style="padding: 16px 24px;">
                    <button type="button" class="btn-outline" onclick="closeBankModal()" style="width: auto; padding: 8px 20px;">Cancel</button>
                    <button type="submit" class="btn-primary" style="width: auto; padding: 8px 24px;">Save Bank Account</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>