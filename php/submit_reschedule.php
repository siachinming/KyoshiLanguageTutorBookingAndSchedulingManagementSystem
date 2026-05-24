<?php
session_start();
include 'config.php';

// Include PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$studentID = $_SESSION['user_id'];
$booking_id = $_POST['booking_id'] ?? 0;
$new_date = $_POST['booking_date'][0] ?? '';
$new_time = $_POST['booking_time'][0] ?? '';
$language = $_POST['language'] ?? '';
$learning_mode = $_POST['mode'] ?? '';
$focus = $_POST['focus'] ?? '';
$notes = $_POST['notes'] ?? '';
$meeting_location = $_POST['location'] ?? '';

// ============================================
// VALIDATION CHECKS
// ============================================

// 1. Check if there's already a PENDING reschedule request for this booking
$checkPending = $conn->prepare("
    SELECT id FROM reschedule_requests 
    WHERE booking_id = ? AND status = 'pending'
");
$checkPending->bind_param("i", $booking_id);
$checkPending->execute();
$existingPending = $checkPending->get_result()->fetch_assoc();

if ($existingPending) {
    $_SESSION['error'] = "You already have a pending reschedule request. Please wait for tutor response.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

// 2. Get original booking details and tutor info
$origQuery = $conn->prepare("
    SELECT b.booking_date, b.booking_time, b.tutor_id, b.status, 
           t.fullname as tutor_name, t.email as tutor_email,
           s.fullname as student_name
    FROM bookings b
    JOIN users t ON b.tutor_id = t.id
    JOIN users s ON b.student_id = s.id
    WHERE b.id = ? AND b.student_id = ?
");
$origQuery->bind_param("ii", $booking_id, $studentID);
$origQuery->execute();
$original = $origQuery->get_result()->fetch_assoc();

if (!$original) {
    $_SESSION['error'] = "Booking not found.";
    header("Location: booking_status.php");
    exit();
}

// 3. Check if booking status is 'confirmed'
if ($original['status'] != 'confirmed') {
    $_SESSION['error'] = "This booking cannot be rescheduled.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

// 4. Check if original class has already passed
$currentDateTime = new DateTime();
$classDateTime = new DateTime($original['booking_date'] . ' ' . $original['booking_time']);
if ($classDateTime < $currentDateTime) {
    $_SESSION['error'] = "Cannot reschedule a class that has already passed.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

// 5. Check if new date/time is same as original
if ($new_date == $original['booking_date'] && $new_time == $original['booking_time']) {
    $_SESSION['error'] = "New date/time cannot be the same as your current booking.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

// 6. Check if requested new date/time is in the future
$requestedDateTime = new DateTime($new_date . ' ' . $new_time);
if ($requestedDateTime < $currentDateTime) {
    $_SESSION['error'] = "Cannot reschedule to a past date or time. Please select a future date/time.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

// 7. Check if the new slot is already booked by another student
$checkSlot = $conn->prepare("
    SELECT id FROM bookings 
    WHERE tutor_id = ? AND booking_date = ? AND booking_time = ? 
    AND status IN ('pending', 'accepted', 'confirmed') AND id != ?
");
$checkSlot->bind_param("issi", $original['tutor_id'], $new_date, $new_time, $booking_id);
$checkSlot->execute();
if ($checkSlot->get_result()->num_rows > 0) {
    $_SESSION['error'] = "This time slot is already booked. Please choose another time.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}

// ============================================
// If all checks pass, insert the reschedule request
// ============================================

// Update booking status to 'rescheduled'
$updateBooking = $conn->prepare("UPDATE bookings SET status = 'rescheduled' WHERE id = ?");
$updateBooking->bind_param("i", $booking_id);
$updateBooking->execute();

// Insert into reschedule_requests
$insert = $conn->prepare("
    INSERT INTO reschedule_requests 
    (booking_id, student_id, tutor_id, old_date, old_time, new_date, new_time, 
     language, learning_mode, focus, notes, meeting_location, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
");
$insert->bind_param("iiisssssssss", 
    $booking_id,
    $studentID,
    $original['tutor_id'],
    $original['booking_date'],
    $original['booking_time'],
    $new_date,
    $new_time,
    $language,
    $learning_mode,
    $focus,
    $notes,
    $meeting_location
);

if ($insert->execute()) {
    // Send in-app notification to tutor
    $notifTitle = "New Reschedule Request";
    $notifMessage = "Student has requested to reschedule a session.";
    
    $notif = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) VALUES (?, ?, ?, 'reschedule', 0, NOW())");
    $notif->bind_param("iss", $original['tutor_id'], $notifTitle, $notifMessage);
    $notif->execute();
    
    // ============================================
    // Send EMAIL to TUTOR
    // ============================================
    $oldDateFormatted = date('l, F j, Y', strtotime($original['booking_date']));
    $oldTimeFormatted = date('g:i A', strtotime($original['booking_time']));
    $newDateFormatted = date('l, F j, Y', strtotime($new_date));
    $newTimeFormatted = date('g:i A', strtotime($new_time));
    $modeLabel = $learning_mode === 'online' ? 'Online' : 'Face to Face';
    
    $tutorDashboardLink = "http://localhost/kyoshi/php/tutor_dashboard.php";
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail->addAddress($original['tutor_email'], $original['tutor_name']);
        $mail->addReplyTo($original['student_email'] ?? '', $original['student_name'] ?? '');
        
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'New Reschedule Request - Kyoshi';
        $mail->Body = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <title>New Reschedule Request</title>
        </head>
        <body>
        <div style='font-family:Segoe UI,Arial,sans-serif;max-width:580px;margin:auto;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 8px 30px rgba(201,79,134,.12);'>
            <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:32px 32px 24px;text-align:center;'>
                <h1 style='margin:0;color:white;font-size:24px;'>New Reschedule Request</h1>
                <p style='margin:8px 0 0;color:rgba(255,255,255,.88);font-size:14px;'>A student wants to reschedule a session</p>
            </div>
            <div style='padding:28px 32px;'>
                <p style='margin:0 0 20px;font-size:15px;color:#342635;'>
                    Hi <strong>" . htmlspecialchars($original['tutor_name']) . "</strong>,
                </p>
                <p style='margin:0 0 20px;font-size:14px;color:#7B6178;line-height:1.6;'>
                    <strong>" . htmlspecialchars($original['student_name']) . "</strong> has requested to reschedule a session.
                </p>
                <div style='background:#FFF1F6;border:1px solid #fce7f3;border-radius:16px;padding:20px;margin-bottom:20px;'>
                    <p style='margin:0 0 12px;font-size:13px;font-weight:700;color:#C94F86;'>Session Details</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Language:</strong> " . htmlspecialchars($language) . "</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Mode:</strong> $modeLabel</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Original Date/Time:</strong> $oldDateFormatted at $oldTimeFormatted</p>
                    <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Requested Date/Time:</strong> $newDateFormatted at $newTimeFormatted</p>
                    " . (!empty($focus) ? "<p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Focus:</strong> " . htmlspecialchars($focus) . "</p>" : "") . "
                    " . (!empty($notes) ? "<p style='margin:6px 0;font-size:14px;color:#342635;'><strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : "") . "
                </div>
                <div style='text-align:center;'>
                    <a href='$tutorDashboardLink'
                       style='display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                              color:white;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px;'>
                        Go to Dashboard
                    </a>
                </div>
                <p style='margin:24px 0 0;font-size:12px;color:#9080a0;text-align:center;line-height:1.6;'>
                    Please respond to this request before the original booking date.
                </p>
            </div>
            <div style='background:#FFF1F6;padding:16px 32px;text-align:center;border-top:1px solid #fce7f3;'>
                <p style='margin:0;font-size:12px;color:#9080a0;'>
                    &copy; " . date('Y') . " Kyoshi
                </p>
            </div>
        </div>
        </body>
        </html>
        ";
        
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Reschedule request email failed: " . $mail->ErrorInfo);
    }
    
    $_SESSION['success'] = "Reschedule request submitted! Tutor will review it.";
    header("Location: booking_detail.php?id=" . $booking_id);
} else {
    $_SESSION['error'] = "Error submitting request: " . $conn->error;
    header("Location: booking_detail.php?id=" . $booking_id);
}
?>