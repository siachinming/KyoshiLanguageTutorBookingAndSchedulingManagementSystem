<?php
// send_session_reminder.php - Run every 5 minutes via cron job
date_default_timezone_set('Asia/Kuala_Lumpur');

// Add error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/session_reminder_error.log');

include __DIR__ . '/config.php';
include __DIR__ . '/insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

require __DIR__ . '/../vendor/autoload.php';

// Check if SMTP constants are defined
if (!defined('SMTP_USER') || !defined('SMTP_PASS')) {
    die("SMTP credentials not defined in config.php\n");
}

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
        AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
    )
");

if (!$stmt) {
    die("Prepare failed: " . $conn->error . "\n");
}

$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$reminder_count = 0;
$email_failures = 0;

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($sessions) . " sessions to process\n";

foreach ($sessions as $session) {
    $bookingDate = date('l, d F Y', strtotime($session['booking_date']));
    $bookingTime = date('g:i A', strtotime($session['booking_time']));
    
    echo "Processing booking #{$session['booking_id']}...\n";
    
    // Send email to STUDENT with error suppression
    $studentEmailSent = sendSessionReminderEmail($session, 'student', $bookingDate, $bookingTime);
    
    // Send email to TUTOR with error suppression
    $tutorEmailSent = sendSessionReminderEmail($session, 'tutor', $bookingDate, $bookingTime);
    
    if ($studentEmailSent || $tutorEmailSent) {
        // In-app notification (mark as sent)
        try {
            insertNotification($conn, $session['student_id'], "Session Ended - Please Confirm", 
                "Your {$session['language']} session with {$session['tutor_name']} has ended. Please confirm your attendance within 24 hours.",
                "session_ended_reminder", "booking_detail.php?id={$session['booking_id']}");
            
            $reminder_count++;
            echo "  ✓ Reminder sent for booking #{$session['booking_id']}\n";
        } catch (Exception $e) {
            echo "  ✗ Notification insert failed: " . $e->getMessage() . "\n";
            $email_failures++;
        }
    } else {
        echo "  ✗ Email sending failed for booking #{$session['booking_id']}\n";
        $email_failures++;
    }
    
    // Small delay to prevent overwhelming the server
    usleep(500000); // 0.5 second delay
}

echo "[" . date('Y-m-d H:i:s') . "] Total reminders sent: $reminder_count\n";
echo "[" . date('Y-m-d H:i:s') . "] Email failures: $email_failures\n";

// ============================================================
// FUNCTION TO SEND SESSION REMINDER EMAILS WITH BETTER ERROR HANDLING
// ============================================================
function sendSessionReminderEmail($session, $recipient, $bookingDate, $bookingTime) {
    // Verify SMTP credentials are set
    if (SMTP_USER === 'your_email@gmail.com' || SMTP_PASS === 'your_app_password') {
        error_log("Please update SMTP_USER and SMTP_PASS in config.php with real credentials");
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Timeout = 30; // 30 second timeout
        
        // Disable TLS verification for local testing (remove in production)
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $mail->setFrom(SMTP_USER, 'Kyoshi');
        
        if ($recipient === 'student') {
            $mail->addAddress($session['student_email'], $session['student_name']);
            $mail->Subject = 'Session Ended - Please Confirm Attendance - Kyoshi';
            $mail->Body = getStudentEmailContent($session, $bookingDate, $bookingTime);
            $mail->AltBody = strip_tags(getStudentEmailContent($session, $bookingDate, $bookingTime));
        } else {
            $mail->addAddress($session['tutor_email'], $session['tutor_name']);
            $mail->Subject = 'Session Ended - Waiting for Student Confirmation - Kyoshi';
            $mail->Body = getTutorEmailContent($session, $bookingDate, $bookingTime);
            $mail->AltBody = strip_tags(getTutorEmailContent($session, $bookingDate, $bookingTime));
        }
        
        $mail->send();
        echo "  ✓ Email sent to {$recipient}: {$session[$recipient . '_email']}\n";
        return true;
        
    } catch (Exception $e) {
        error_log("[" . date('Y-m-d H:i:s') . "] Email failed for booking {$session['booking_id']} to {$recipient}: " . $mail->ErrorInfo);
        echo "  ✗ Email failed to {$recipient}: " . $mail->ErrorInfo . "\n";
        return false;
    }
}

function getStudentEmailContent($session, $bookingDate, $bookingTime) {
    return "
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
                    <a href='http://kyoshitutor.site/php/booking_detail.php?id={$session['booking_id']}#confirm' class='btn-confirm'>
                        ✅ Confirm Attendance
                    </a>
                    <a href='http://kyoshitutor.site/php/booking_detail.php?id={$session['booking_id']}#report' class='btn-report'>
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
}

function getTutorEmailContent($session, $bookingDate, $bookingTime) {
    return "
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
?>