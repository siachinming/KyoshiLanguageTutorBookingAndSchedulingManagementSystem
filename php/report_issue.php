<?php
session_start();
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Check if it's POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$booking_id = intval($_POST['booking_id'] ?? 0);
$issue_type = $_POST['issue_type'] ?? '';
$message = trim($_POST['message'] ?? '');
$proof = $_FILES['proof'] ?? null;

if (!$booking_id || !$issue_type) {
    $_SESSION['error'] = "Please fill in all required fields.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

// ============================================
// VALIDATION FOR TUTOR NO-SHOW - PROOF REQUIRED
// ============================================
if ($issue_type === 'tutor_no_show') {
    // Check if proof file was uploaded
    if (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['error'] = "⚠️ PROOF REQUIRED: For 'Tutor didn't show up' reports, you MUST upload proof (screenshot or photo).";
        header("Location: booking_detail.php?id=" . $booking_id);
        exit();
    }
    
    // Check if file is empty
    if ($_FILES['proof']['size'] === 0) {
        $_SESSION['error'] = "The uploaded file is empty. Please upload a valid proof file.";
        header("Location: booking_detail.php?id=" . $booking_id);
        exit();
    }
    
    // Check file size (max 5MB)
    if ($_FILES['proof']['size'] > 5 * 1024 * 1024) {
        $_SESSION['error'] = "Proof file is too large. Maximum size is 5MB.";
        header("Location: booking_detail.php?id=" . $booking_id);
        exit();
    }
    
    // Check file type
    $allowed_types = ['image/jpeg', 'image/png', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $file_type = finfo_file($finfo, $_FILES['proof']['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($file_type, $allowed_types)) {
        $_SESSION['error'] = "Invalid file type. Please upload JPG, PNG, or PDF only.";
        header("Location: booking_detail.php?id=" . $booking_id);
        exit();
    }
}

// Get booking details
$stmt = $conn->prepare("
    SELECT b.*, 
           t.fullname as tutor_name, t.email as tutor_email, t.id as tutor_id,
           s.fullname as student_name, s.email as student_email, s.id as student_id
    FROM bookings b
    JOIN users t ON b.tutor_id = t.id
    JOIN users s ON b.student_id = s.id
    WHERE b.id = ?
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();

if (!$booking) {
    $_SESSION['error'] = "Booking not found.";
    header("Location: booking_status.php");
    exit();
}

// Handle file upload
$proof_path = null;
if ($proof && $proof['error'] === UPLOAD_ERR_OK) {
    $upload_dir = '../uploads/reports/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $proof['name']);
    $proof_path = $upload_dir . $filename;
    move_uploaded_file($proof['tmp_name'], $proof_path);
}

// ============================================
// DETERMINE SEVERITY
// ============================================
$serious_types = ['tutor_no_show', 'student_no_show', 'harassment', 'fraud'];
$is_serious = in_array($issue_type, $serious_types);
$resolution_type = $is_serious ? 'admin' : 'student_tutor';

// ============================================
// INSERT INTO DISPUTES TABLE
// ============================================
$stmt = $conn->prepare("
    INSERT INTO disputes (booking_id, student_id, tutor_id, issue_type, message, proof_image, status, resolution_type, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
");
$stmt->bind_param("iiissss", $booking_id, $booking['student_id'], $booking['tutor_id'], $issue_type, $message, $proof_path, $resolution_type);

if (!$stmt->execute()) {
    $_SESSION['error'] = "Failed to submit report: " . $conn->error;
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

$dispute_id = $conn->insert_id;
$stmt->close();

// ============================================
// UPDATE BOOKING STATUS TO DISPUTED (ONLY FOR SERIOUS ISSUES)
// ============================================
if ($is_serious && $booking['status'] !== 'disputed') {
    $updateStmt = $conn->prepare("
        UPDATE bookings 
        SET status = 'disputed' 
        WHERE id = ?
    ");
    $updateStmt->bind_param("i", $booking_id);
    $updateStmt->execute();
    $updateStmt->close();
    
    // Also update session_completion
    $completionStmt = $conn->prepare("
        INSERT INTO session_completion (booking_id, status, dispute_reason, disputed_at)
        VALUES (?, 'disputed', ?, NOW())
        ON DUPLICATE KEY UPDATE 
        status = 'disputed', 
        dispute_reason = ?,
        disputed_at = NOW()
    ");
    $completionStmt->bind_param("iss", $booking_id, $issue_type, $issue_type);
    $completionStmt->execute();
    $completionStmt->close();
}

// ============================================
// SEND NOTIFICATIONS
// ============================================

if ($is_serious) {
    // Serious issue - notify admin and both parties
    
    // Student notification
    $studentMsg = "Your report for the {$booking['language']} session has been submitted. This is a serious issue and admin will review within 2-3 business days.";
    insertNotification($conn, $booking['student_id'], "Serious Issue Reported - Under Review", $studentMsg, "dispute", "booking_detail.php?id={$booking_id}");
    
    // Tutor notification
    $tutorMsg = "A student has reported a SERIOUS issue with your {$booking['language']} session. Admin will review and contact you.";
    insertNotification($conn, $booking['tutor_id'], "Serious Issue Reported - Under Review", $tutorMsg, "dispute", "tutor_booking_detail.php?id={$booking_id}");
    
} else {
    // Minor issue - notify tutor for resolution
    
    // Student notification
    $studentMsg = "Your issue has been reported. The tutor has been notified and will contact you to resolve it within 48 hours.";
    insertNotification($conn, $booking['student_id'], "Issue Reported - Tutor Notified", $studentMsg, "dispute", "booking_detail.php?id={$booking_id}");
    
    // Tutor notification with resolution link
    $tutorMsg = "A student has reported an issue with your {$booking['language']} session.\n\n";
    $tutorMsg .= "Issue: " . ucfirst(str_replace('_', ' ', $issue_type)) . "\n";
    $tutorMsg .= "Message: " . ($message ?: "No details provided") . "\n\n";
    $tutorMsg .= "Please resolve this issue within 48 hours, otherwise it will be escalated to admin.";
    insertNotification($conn, $booking['tutor_id'], "Student Reported Issue - Please Resolve", $tutorMsg, "dispute_resolution", "resolve_dispute.php?id=$dispute_id&booking_id=$booking_id");
}

// ============================================
// SEND EMAILS
// ============================================

$bookingDate = date('l, d F Y', strtotime($booking['booking_date']));
$bookingTime = date('g:i A', strtotime($booking['booking_time']));

// Email to STUDENT (confirmation)
sendStudentReportEmail($booking, $issue_type, $message, $is_serious, $bookingDate, $bookingTime);

// Email to TUTOR (only for serious issues or if tutor_no_show)
if ($is_serious || $issue_type === 'tutor_no_show') {
    sendTutorReportEmail($booking, $issue_type, $message, $is_serious, $bookingDate, $bookingTime);
}

// Email to ADMIN (always)
sendAdminReportEmail($booking, $issue_type, $message, $proof_path, $bookingDate, $bookingTime, $dispute_id);

$_SESSION['success'] = $is_serious 
    ? "Report submitted. Admin will review within 2-3 business days."
    : "Report submitted. The tutor has been notified and will contact you.";

if ($role === 'student') {
    header("Location: booking_detail.php?id=" . $booking_id . "&reported=1");
} else {
    header("Location: tutor_booking_detail.php?id=" . $booking_id . "&reported=1");
}
exit();

function sendStudentReportEmail($booking, $issue_type, $message, $is_serious, $bookingDate, $bookingTime) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true); 
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($booking['student_email'], $booking['student_name']);
        
        $issueLabels = [
            'tutor_no_show' => 'Tutor Did Not Attend',
            'technical_issues' => 'Technical Issues',
            'wrong_materials' => 'Wrong Materials Provided',
            'other' => 'Other Issue'
        ];
        $issueLabel = $issueLabels[$issue_type] ?? ucfirst(str_replace('_', ' ', $issue_type));
        
        if ($is_serious) {
            $mail->Subject = 'Serious Issue Reported - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <h2 style='color:#dc2626;'>Serious Issue Reported</h2>
                </div>
                <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                <p>Your report for the <strong>{$booking['language']}</strong> session has been submitted.</p>
                <div style='background:#f0f9ff;border-radius:12px;padding:16px;margin:20px 0;'>
                    <p><strong>Session:</strong> {$bookingDate} at {$bookingTime}</p>
                    <p><strong>Issue Type:</strong> {$issueLabel}</p>
                    <p><strong>Description:</strong> " . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                <div style='background:#fff3cd;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #ffc107;'>
                    <p style='margin:0;color:#856404;'>Our admin team will review your report within 2-3 business days.</p>
                </div>
            </div>
            ";
        } else {
            $mail->Subject = 'Issue Reported - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <h2 style='color:#E75A9B;'>Issue Reported</h2>
                </div>
                <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                <p>Your issue has been reported to the tutor.</p>
                <div style='background:#f0f9ff;border-radius:12px;padding:16px;margin:20px 0;'>
                    <p><strong>Session:</strong> {$bookingDate} at {$bookingTime}</p>
                    <p><strong>Issue Type:</strong> {$issueLabel}</p>
                    <p><strong>Description:</strong> " . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                <p>The tutor has been notified and will contact you to resolve this issue.</p>
            </div>
            ";
        }
        $mail->send();
    } catch (Exception $e) {
        error_log("Student report email failed: " . $e->getMessage());
    }
}

function sendTutorReportEmail($booking, $issue_type, $message, $is_serious, $bookingDate, $bookingTime) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true); 
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
        
        $issueLabels = [
            'tutor_no_show' => 'Tutor Did Not Attend',
            'technical_issues' => 'Technical Issues',
            'wrong_materials' => 'Wrong Materials Provided',
            'other' => 'Other Issue'
        ];
        $issueLabel = $issueLabels[$issue_type] ?? ucfirst(str_replace('_', ' ', $issue_type));
        
        if ($is_serious) {
            $mail->Subject = '⚠️ Serious Issue Reported - Session Disputed - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <h2 style='color:#dc2626;'>Serious Issue Reported ⚠️</h2>
                </div>
                <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
                <p>A serious issue has been reported for your <strong>{$booking['language']}</strong> session with <strong>{$booking['student_name']}</strong> on <strong>{$bookingDate} at {$bookingTime}</strong>.</p>
                <div style='background:#fff3cd;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #ffc107;'>
                    <p><strong>Issue Type:</strong> {$issueLabel}</p>
                    <p><strong>Student's Message:</strong> " . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                <p>The session has been marked as <strong style='color:#dc2626;'>DISPUTED</strong>. Admin will review and contact you.</p>
            </div>
            ";
        } else {
            $mail->Subject = 'Student Reported an Issue - Please Resolve - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <h2 style='color:#f59e0b;'>Student Reported an Issue</h2>
                </div>
                <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
                <p>A student has reported an issue with your <strong>{$booking['language']}</strong> session.</p>
                <div style='background:#f0f9ff;border-radius:12px;padding:16px;margin:20px 0;'>
                    <p><strong>Session:</strong> {$bookingDate} at {$bookingTime}</p>
                    <p><strong>Issue Type:</strong> {$issueLabel}</p>
                    <p><strong>Student's Message:</strong> " . nl2br(htmlspecialchars($message)) . "</p>
                </div>
                <p>Please contact the student to resolve this issue within 48 hours.</p>
            </div>
            ";
        }
        $mail->send();
    } catch (Exception $e) {
        error_log("Tutor report email failed: " . $e->getMessage());
    }
}

function sendAdminReportEmail($booking, $issue_type, $message, $proof_path, $bookingDate, $bookingTime, $dispute_id) {
    $mail = new PHPMailer(true);
    $adminEmail = 'admin@kyoshi.com'; // Change to your admin email
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true); 
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi Admin Alert');
        $mail->addAddress($adminEmail, 'Admin');
        $mail->Subject = '⚠️ New Dispute #' . $dispute_id . ' - Kyoshi';
        
        $issueLabels = [
            'tutor_no_show' => 'Tutor Did Not Attend',
            'technical_issues' => 'Technical Issues',
            'wrong_materials' => 'Wrong Materials Provided',
            'other' => 'Other Issue'
        ];
        $issueLabel = $issueLabels[$issue_type] ?? ucfirst(str_replace('_', ' ', $issue_type));
        
        $proofLink = $proof_path ? "<p><strong>Proof Attachment:</strong> <a href='http://localhost/kyoshi/{$proof_path}'>View Proof</a></p>" : "";
        
        $mail->Body = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;'>
                <h2 style='color:#dc2626;'>New Dispute Submitted</h2>
                <p style='font-size:12px;color:#999;'>Dispute ID: #{$dispute_id}</p>
            </div>
            <p><strong>Booking ID:</strong> {$booking['id']}</p>
            <p><strong>Student:</strong> {$booking['student_name']} ({$booking['student_email']})</p>
            <p><strong>Tutor:</strong> {$booking['tutor_name']} ({$booking['tutor_email']})</p>
            <p><strong>Session:</strong> {$bookingDate} at {$bookingTime}</p>
            <p><strong>Language:</strong> {$booking['language']}</p>
            <p><strong>Issue Type:</strong> {$issueLabel}</p>
            <p><strong>Description:</strong> " . nl2br(htmlspecialchars($message)) . "</p>
            {$proofLink}
            <div style='text-align:center;margin-top:20px;'>
                <a href='http://localhost/kyoshi/admin/dispute_detail.php?id={$dispute_id}' 
                   style='display:inline-block;padding:10px 20px;background:#dc2626;color:white;border-radius:30px;text-decoration:none;font-weight:bold;'>
                    Review Dispute →
                </a>
            </div>
        </div>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Admin report email failed: " . $e->getMessage());
    }
}
?>