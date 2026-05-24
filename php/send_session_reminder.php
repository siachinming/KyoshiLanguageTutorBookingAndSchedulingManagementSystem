<?php
// send_session_reminder.php - Run every minute via cron job

include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

// Get sessions that ended within the last 1 minute
$stmt = $conn->prepare("
    SELECT 
        b.id as booking_id,
        b.student_id,
        b.tutor_id,
        b.language,
        b.booking_date,
        b.booking_time,
        s.fullname as student_name,
        s.email as student_email,
        t.fullname as tutor_name
    FROM bookings b
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    WHERE b.status = 'confirmed'
    AND CONCAT(b.booking_date, ' ', b.booking_time) < NOW()
    AND CONCAT(b.booking_date, ' ', b.booking_time) > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    AND NOT EXISTS (
        SELECT 1 FROM session_completion sc WHERE sc.booking_id = b.id
    )
");
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($sessions as $session) {
    // Send email to STUDENT
    sendSessionReminderEmail($session, 'student');
    
    // Send email to TUTOR
    sendSessionReminderEmail($session, 'tutor');
    
    // Also send in-app notification
    insertNotification($conn, $session['student_id'], "Session Ended - Please Confirm", 
        "Your {$session['language']} session has ended. Please confirm your attendance.",
        "session_ended", "booking_detail.php?id={$session['booking_id']}");
    
    echo "Reminder sent for booking #{$session['booking_id']}\n";
}

function sendSessionReminderEmail($session, $recipient) {
    $mail = new PHPMailer(true);
    $date = date('l, d F Y', strtotime($session['booking_date']));
    $time = date('g:i A', strtotime($session['booking_time']));
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        
        if ($recipient === 'student') {
            $mail->addAddress($session['student_email'], $session['student_name']);
            $mail->Subject = 'Session Ended - Please Confirm Attendance';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;margin-bottom:24px;'>
                    <img src='http://localhost/kyoshi/assets/img/logo.png' alt='Kyoshi' style='height:50px;'>
                    <h2 style='color:#E75A9B;'>Session Ended</h2>
                </div>
                <p>Dear <strong>{$session['student_name']}</strong>,</p>
                <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['tutor_name']}</strong> has ended.</p>
                <p>Please confirm your attendance or report any issues:</p>
                <div style='text-align:center;margin:20px 0;'>
                    <a href='http://localhost/kyoshi/php/booking_detail.php?id={$session['booking_id']}' 
                       style='display:inline-block;padding:10px 20px;background:#4caf50;color:white;border-radius:30px;text-decoration:none;margin-right:10px;'>
                        ✅ Confirm Attendance
                    </a>
                    <a href='http://localhost/kyoshi/php/booking_detail.php?id={$session['booking_id']}' 
                       style='display:inline-block;padding:10px 20px;background:#ff9800;color:white;border-radius:30px;text-decoration:none;'>
                        ⚠️ Report Issue
                    </a>
                </div>
                <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>
                    If you don't confirm within 24 hours, the session will be auto-completed.
                </p>
            </div>
            ";
        } else {
            $mail->addAddress($session['tutor_email'], $session['tutor_name']);
            $mail->Subject = 'Session Ended - Waiting for Student Confirmation';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;margin-bottom:24px;'>
                    <img src='http://localhost/kyoshi/assets/img/logo.png' alt='Kyoshi' style='height:50px;'>
                    <h2 style='color:#E75A9B;'>Session Completed</h2>
                </div>
                <p>Dear <strong>{$session['tutor_name']}</strong>,</p>
                <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['student_name']}</strong> has ended.</p>
                <p>The student has been asked to confirm attendance. Payment will be processed after confirmation.</p>
                <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>
                    If the student doesn't confirm within 24 hours, it will be auto-completed.
                </p>
            </div>
            ";
        }
        $mail->send();
    } catch (Exception $e) {
        error_log("Reminder email failed: " . $e->getMessage());
    }
}
?>