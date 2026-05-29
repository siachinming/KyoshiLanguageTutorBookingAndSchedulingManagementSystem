<?php
// send_session_reminder.php - Run every 5 minutes via cron job
date_default_timezone_set('Asia/Kuala_Lumpur');
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

// Get sessions that ended within the last 5 minutes
// AND haven't had a reminder sent yet
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
        t.fullname as tutor_name,
        t.email as tutor_email
    FROM bookings b
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    WHERE b.status = 'confirmed'
    AND TIMESTAMP(b.booking_date, b.booking_time) BETWEEN DATE_SUB(NOW(), INTERVAL 5 MINUTE) AND NOW()
    AND NOT EXISTS (
        SELECT 1 FROM notifications n 
        WHERE n.type = 'session_ended_reminder' 
        AND n.link LIKE CONCAT('%', b.id, '%')
    )
");
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$reminder_count = 0;

foreach ($sessions as $session) {
    $bookingDate = date('l, d F Y', strtotime($session['booking_date']));
    $bookingTime = date('g:i A', strtotime($session['booking_time']));
    
    // Send email to STUDENT
    sendSessionReminderEmail($session, 'student', $bookingDate, $bookingTime);
    
    // Send email to TUTOR
    sendSessionReminderEmail($session, 'tutor', $bookingDate, $bookingTime);
    
    // In-app notification (mark as sent)
    insertNotification($conn, $session['student_id'], "Session Ended - Please Confirm", 
        "Your {$session['language']} session with {$session['tutor_name']} has ended. Please confirm your attendance within 24 hours.",
        "session_ended_reminder", "booking_detail.php?id={$session['booking_id']}");
    
    $reminder_count++;
    echo "[" . date('Y-m-d H:i:s') . "] Reminder sent for booking #{$session['booking_id']}\n";
}

echo "[" . date('Y-m-d H:i:s') . "] Total reminders sent: $reminder_count\n";

// ============================================================
// FUNCTION TO SEND SESSION REMINDER EMAILS
// ============================================================
function sendSessionReminderEmail($session, $recipient, $bookingDate, $bookingTime) {
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
        
        if ($recipient === 'student') {
            $mail->addAddress($session['student_email'], $session['student_name']);
            $mail->Subject = 'Session Ended - Please Confirm Attendance - Kyoshi';
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
                    .container { max-width: 550px; margin: 0 auto; padding: 20px; }
                    .card { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
                    .header { text-align: center; margin-bottom: 24px; }
                    .header h2 { color: #E75A9B; margin: 0; }
                    .session-info { background: #f0f9ff; border-radius: 16px; padding: 20px; margin: 20px 0; }
                    .info-row { margin: 10px 0; }
                    .btn-confirm { display: inline-block; padding: 12px 28px; background: #28a745; color: white; text-decoration: none; border-radius: 30px; font-weight: bold; margin-right: 10px; }
                    .btn-report { display: inline-block; padding: 12px 28px; background: #ffc107; color: #333; text-decoration: none; border-radius: 30px; font-weight: bold; }
                    .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='card'>
                        <div class='header'>
                            <h2>Session Ended</h2>
                        </div>
                        <p>Dear <strong>{$session['student_name']}</strong>,</p>
                        <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['tutor_name']}</strong> has ended.</p>
                        
                        <div class='session-info'>
                            <div class='info-row'><strong>Date:</strong> {$bookingDate}</div>
                            <div class='info-row'><strong>Time:</strong> {$bookingTime}</div>
                            <div class='info-row'><strong>Tutor:</strong> {$session['tutor_name']}</div>
                        </div>
                        
                        <p>Please confirm your attendance or report any issues:</p>
                        <div style='text-align: center; margin: 25px 0;'>
                            <a href='http://localhost/kyoshi/php/booking_detail.php?id={$session['booking_id']}#confirm' class='btn-confirm'>
                                ✅ Confirm Attendance
                            </a>
                            <a href='http://localhost/kyoshi/php/booking_detail.php?id={$session['booking_id']}#report' class='btn-report'>
                                ⚠️ Report Issue
                            </a>
                        </div>
                        
                        <div class='footer'>
                            <p>If you don't confirm within 24 hours, the session will be auto-completed.</p>
                            <p>© " . date('Y') . " Kyoshi - Language Learning Platform</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            ";
        } else {
            $mail->addAddress($session['tutor_email'], $session['tutor_name']);
            $mail->Subject = 'Session Ended - Waiting for Student Confirmation - Kyoshi';
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; padding: 0; background: #f5f5f5; }
                    .container { max-width: 550px; margin: 0 auto; padding: 20px; }
                    .card { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 8px 24px rgba(0,0,0,0.1); }
                    .header { text-align: center; margin-bottom: 24px; }
                    .header h2 { color: #E75A9B; margin: 0; }
                    .session-info { background: #f0f9ff; border-radius: 16px; padding: 20px; margin: 20px 0; }
                    .info-row { margin: 10px 0; }
                    .footer { text-align: center; font-size: 12px; color: #999; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='card'>
                        <div class='header'>
                            <h2>Session Completed</h2>
                        </div>
                        <p>Dear <strong>{$session['tutor_name']}</strong>,</p>
                        <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['student_name']}</strong> has ended.</p>
                        
                        <div class='session-info'>
                            <div class='info-row'><strong>Date:</strong> {$bookingDate}</div>
                            <div class='info-row'><strong>Time:</strong> {$bookingTime}</div>
                            <div class='info-row'><strong>Student:</strong> {$session['student_name']}</div>
                        </div>
                        
                        <p>The student has been asked to confirm attendance. Payment will be processed after confirmation.</p>
                        
                        <div class='footer'>
                            <p>If the student doesn't confirm within 24 hours, the session will be auto-completed.</p>
                            <p>© " . date('Y') . " Kyoshi - Language Learning Platform</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            ";
        }
        $mail->send();
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] Reminder email failed for booking {$session['booking_id']}: " . $e->getMessage());
    }
}
?>