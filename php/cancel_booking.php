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
$bookingId = intval($_POST['booking_id'] ?? $_GET['id'] ?? 0);

if (!$bookingId) {
    header("Location: booking_status.php");
    exit();
}

// Get booking details with user emails
$stmt = $conn->prepare("
    SELECT 
        b.*,
        s.fullname AS student_name, s.email AS student_email,
        t.fullname AS tutor_name, t.email AS tutor_email
    FROM bookings b
    JOIN users s ON s.id = b.student_id
    JOIN users t ON t.id = b.tutor_id
    WHERE b.id = ? AND b.student_id = ?
");
$stmt->bind_param("ii", $bookingId, $userID);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    header("Location: booking_status.php?error=notfound");
    exit();
}

// Check status - can cancel pending or accepted bookings
if (!in_array($booking['status'], ['pending', 'accepted'])) {
    header("Location: booking_status.php?error=cannot_cancel");
    exit();
}

// Get cancel reason from POST (from the modal form)
$cancelReason = $_POST['cancel_reason'] ?? 'No reason provided';

// Update booking to cancelled with the provided reason
$stmt = $conn->prepare("
    UPDATE bookings 
    SET status = 'cancelled',
        cancelled_by = 'student',
        cancel_reason = ?
    WHERE id = ? AND student_id = ?
");
$stmt->bind_param("sii", $cancelReason, $bookingId, $userID);
$stmt->execute();

if ($stmt->affected_rows === 0) {
    $stmt->close();
    header("Location: booking_status.php?error=cancel_failed");
    exit();
}
$stmt->close();

// Prepare data for emails
$studentName = $booking['student_name'];
$tutorName = $booking['tutor_name'];
$language = $booking['language'];
$date = date('l, d F Y', strtotime($booking['booking_date']));
$time = date('g:i A', strtotime($booking['booking_time']));
$amount = $booking['amount'] ?? 0;

// Notify tutor via database notification
insertNotification(
    $conn,
    $booking['tutor_id'],
    "Booking Cancelled",
    "$studentName cancelled their $language lesson on $date at $time. Reason: $cancelReason",
    "booking_cancelled",
    "booking_detail.php?id=$bookingId"
);

// Notify student via database notification
insertNotification(
    $conn,
    $userID,
    "Booking Cancelled",
    "You have cancelled your $language lesson with $tutorName on $date at $time. Reason: $cancelReason",
    "booking_cancelled",
    "booking_status.php"
);

// Send EMAIL to STUDENT
try {
    $studentMail = new PHPMailer(true);
    $studentMail->isSMTP();
    $studentMail->Host       = 'smtp.gmail.com';
    $studentMail->SMTPAuth   = true;
    $studentMail->Username   = SMTP_USER;
    $studentMail->Password   = SMTP_PASS;
    $studentMail->SMTPSecure = 'tls';
    $studentMail->Port       = 587;
    $studentMail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $studentMail->addAddress($booking['student_email'], $studentName);
    $studentMail->isHTML(true);
    $studentMail->Subject = 'Booking Cancelled - Kyoshi';
    $studentMail->Body    = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <img src='http://localhost/Kyoshi/assets/img/logo.png' alt='Kyoshi' style='height:50px;'>
                <h2 style='color:#E75A9B;margin:10px 0 0;'>Booking Cancelled</h2>
            </div>
            
            <p>Dear <strong>" . htmlspecialchars($studentName) . "</strong>,</p>
            
            <p>Your booking has been <strong style='color:#dc3545;'>CANCELLED</strong> as requested.</p>
            
            <div style='background:#f8f9fa;padding:16px;border-radius:12px;margin:20px 0;'>
                <h3 style='margin:0 0 12px;color:#342635;'>Cancelled Session Details:</h3>
                <table style='width:100%;'>
                    <tr><td style='padding:6px 0;'><strong>Language:</strong></td><td>" . htmlspecialchars($language) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Tutor:</strong></td><td>" . htmlspecialchars($tutorName) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Date:</strong></td><td>" . $date . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Time:</strong></td><td>" . $time . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Cancellation Reason:</strong></td><td style='color:#dc3545;'>" . htmlspecialchars($cancelReason) . "</td></tr>
                </table>
            </div>
            
            <p>If you have already made a payment, please contact support for refund.</p>
            
            <div style='text-align:center;margin:30px 0 20px;'>
                <a href='http://localhost/kyoshi/php/find_language.php' 
                   style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                          color:white;border-radius:40px;text-decoration:none;font-weight:bold;'>
                    Book New Session →
                </a>
            </div>
            
            <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
            <p style='font-size:12px;color:#999;text-align:center;'>
                This is an automated message. Please do not reply to this email.<br>
                &copy; " . date('Y') . " Kyoshi Learning. All rights reserved.
            </p>
        </div>
    ";
    $studentMail->send();
} catch (Exception $e) {
    // Log error but continue
    error_log("Student email failed: " . $e->getMessage());
}

// Send EMAIL to TUTOR
try {
    $tutorMail = new PHPMailer(true);
    $tutorMail->isSMTP();
    $tutorMail->Host       = 'smtp.gmail.com';
    $tutorMail->SMTPAuth   = true;
    $tutorMail->Username   = SMTP_USER;
    $tutorMail->Password   = SMTP_PASS;
    $tutorMail->SMTPSecure = 'tls';
    $tutorMail->Port       = 587;
    $tutorMail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $tutorMail->addAddress($booking['tutor_email'], $tutorName);
    $tutorMail->isHTML(true);
    $tutorMail->Subject = 'Booking Cancelled by Student - Kyoshi';
    $tutorMail->Body    = "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <div style='text-align:center;margin-bottom:24px;'>
                <h2 style='color:#E75A9B;margin:10px 0 0;'>Booking Cancelled</h2>
            </div>
            
            <p>Dear <strong>" . htmlspecialchars($tutorName) . "</strong>,</p>
            
            <p>A student has cancelled their booking. Please see details below:</p>
            
            <div style='background:#f8f9fa;padding:16px;border-radius:12px;margin:20px 0;'>
                <h3 style='margin:0 0 12px;color:#342635;'>Cancelled Session Details:</h3>
                <table style='width:100%;'>
                    <tr><td style='padding:6px 0;'><strong>Student:</strong></td><td>" . htmlspecialchars($studentName) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Language:</strong></td><td>" . htmlspecialchars($language) . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Date:</strong></td><td>" . $date . "</td></tr>
                    <tr><td style='padding:6px 0;'><strong>Time:</strong></td><td>" . $time . "</td></tr>
<tr><td style='padding:6px 0;'><strong>Cancellation Reason:</strong></td><td style='color:#dc3545;'>" . htmlspecialchars($cancelReason) . "</td></tr>
                </table>
            </div>
            
            <p>Your available time slots have been freed up for other students.</p>
            
            <div style='text-align:center;margin:30px 0 20px;'>
                <a href='http://localhost/kyoshi/php/tutor_dashboard.php' 
                   style='display:inline-block;padding:12px 28px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                          color:white;border-radius:40px;text-decoration:none;font-weight:bold;'>
                    View Dashboard →
                </a>
            </div>
            
            <hr style='border:none;border-top:1px solid #eee;margin:20px 0;'>
            <p style='font-size:12px;color:#999;text-align:center;'>
                This is an automated message. Please do not reply to this email.<br>
                &copy; " . date('Y') . " Kyoshi Learning. All rights reserved.
            </p>
        </div>
    ";
    $tutorMail->send();
} catch (Exception $e) {
    // Log error but continue
    error_log("Tutor email failed: " . $e->getMessage());
}

header("Location: booking_status.php?cancelled=1");
exit();
?>