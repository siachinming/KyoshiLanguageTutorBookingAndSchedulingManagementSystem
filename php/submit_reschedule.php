<?php
session_start();
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }
$userID = $_SESSION['user_id'];

$bookingID = intval($_POST['booking_id'] ?? 0);
$language  = trim($_POST['language'] ?? '');
$mode      = trim($_POST['mode'] ?? '');
$focus     = trim($_POST['focus'] ?? '');
$notes     = trim($_POST['notes'] ?? '');
$location  = trim($_POST['location'] ?? '');
$dates     = $_POST['booking_date'] ?? [];
$times     = $_POST['booking_time'] ?? [];

if (!$bookingID || !$language || !$mode || empty($dates)) {
    header("Location: booking_status.php");
    exit();
}

// Verify booking belongs to student and is confirmed
$stmt = $conn->prepare("
SELECT b.id, b.tutor_id, b.booking_date, b.booking_time,
       u.fullname AS tutor_name, u.email AS tutor_email,
       s.fullname AS student_name
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    JOIN users s ON b.student_id = s.id
    WHERE b.id = ? AND b.student_id = ? AND b.status = 'confirmed'
");
$stmt->bind_param("ii", $bookingID, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) { header("Location: booking_status.php"); exit(); }

// Use first date/time
$newDate = $dates[0] ?? '';
$newTime = $times[0] ?? '';
if (!$newDate || !$newTime) { header("Location: booking_status.php"); exit(); }
$meetingLoc = $mode === 'face_to_face' ? $location : null;
$oldDate = $booking['booking_date'];
$oldTime = $booking['booking_time'];

$stmt = $conn->prepare("
    INSERT INTO reschedule_requests (
        booking_id, student_id, tutor_id,
        old_date, old_time,
        new_date, new_time,
        language, learning_mode,
        focus, notes, meeting_location,
        status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");

$stmt->bind_param(
    "iiisssssssss",
    $bookingID,
    $userID,
    $booking['tutor_id'],
    $oldDate,
    $oldTime,
    $newDate,
    $newTime,
    $language,
    $mode,
    $focus,
    $notes,
    $meetingLoc
);
$stmt->execute();
$stmt->close();

// Send email to tutor
$formattedDate = date('D, d M Y', strtotime($newDate));
$formattedTime = date('g:i A', strtotime($newTime));
$modeLabel     = $mode === 'online' ? 'Online' : 'Face to Face';
$locationLine  = $meetingLoc ? "<p><strong>Location:</strong> {$meetingLoc}</p>" : '';
$focusLine     = $focus ? "<p><strong>Focus:</strong> {$focus}</p>" : '';
$notesLine     = $notes ? "<p><strong>Notes:</strong> {$notes}</p>" : '';

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
    $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);

    $mail->isHTML(true);
    $mail->Subject = '📅 Reschedule Request — Booking #' . $bookingID;
    $mail->Body    = "
        <div style='font-family:Segoe UI,sans-serif;max-width:560px;margin:auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
            <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:28px 32px;'>
                <h2 style='margin:0;color:white;font-size:20px;'>Reschedule Request</h2>
                <p style='margin:6px 0 0;color:rgba(255,255,255,.85);font-size:14px;'>Booking #{$bookingID}</p>
            </div>
            <div style='padding:28px 32px;'>
                <p style='font-size:15px;color:#342635;'>Hi <strong>{$booking['tutor_name']}</strong>,</p>
                <p style='font-size:14px;color:#7B6178;line-height:1.6;'>
                    Your student <strong>{$booking['student_name']}</strong> has requested to reschedule their session. Please review and approve the new schedule.
                </p>

                <div style='background:#FFF1F6;border:1px solid rgba(231,90,155,.2);border-radius:12px;padding:18px;margin:20px 0;'>
                    <p style='margin:0 0 10px;font-size:13px;font-weight:900;color:#C94F86;text-transform:uppercase;letter-spacing:.5px;'>New Schedule</p>
                    <p style='margin:0 0 6px;font-size:14px;color:#342635;'><strong>Language:</strong> {$language}</p>
                    <p style='margin:0 0 6px;font-size:14px;color:#342635;'><strong>Date:</strong> {$formattedDate}</p>
                    <p style='margin:0 0 6px;font-size:14px;color:#342635;'><strong>Time:</strong> {$formattedTime}</p>
                    <p style='margin:0 0 6px;font-size:14px;color:#342635;'><strong>Mode:</strong> {$modeLabel}</p>
                    {$locationLine}
                    {$focusLine}
                    {$notesLine}
                </div>

                <p style='font-size:13px;color:#7B6178;line-height:1.6;'>
                    Please log in to your tutor dashboard to approve or decline this reschedule request.
                </p>

                <div style='margin-top:24px;padding-top:20px;border-top:1px solid rgba(46,42,59,.08);font-size:12px;color:#9080a0;'>
                    This is an automated message from Kyoshi. Please do not reply to this email.
                </div>
            </div>
        </div>
    ";

    $mail->send();
} catch (Exception $e) {
    // Email failed silently — booking already updated, don't block the redirect
}

header("Location: booking_detail.php?id=$bookingID&rescheduled=1");
exit();