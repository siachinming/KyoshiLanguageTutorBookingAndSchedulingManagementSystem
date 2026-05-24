<?php
// auto_complete_sessions.php - Run this file daily via cron job or Task Scheduler

include __DIR__ . '/config.php';
include __DIR__ . '/insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../vendor/autoload.php';

// Get confirmed sessions that ended more than 24 hours ago
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
    AND CONCAT(b.booking_date, ' ', b.booking_time) < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    AND NOT EXISTS (
        SELECT 1 FROM session_completion sc WHERE sc.booking_id = b.id
    )
");
$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$auto_completed_count = 0;

foreach ($sessions as $session) {
    // Auto-complete the booking
    $updateStmt = $conn->prepare("
        UPDATE bookings 
        SET status = 'completed', 
            completed_at = NOW(),
            auto_completed = 1
        WHERE id = ?
    ");
    $updateStmt->bind_param("i", $session['booking_id']);
    $updateStmt->execute();
    
    if ($updateStmt->affected_rows > 0) {
        $auto_completed_count++;
        
        // Add to session_completion table
        $completionStmt = $conn->prepare("
            INSERT INTO session_completion (booking_id, tutor_confirmed, student_confirmed, completed_at)
            VALUES (?, 1, 1, NOW())
        ");
        $completionStmt->bind_param("i", $session['booking_id']);
        $completionStmt->execute();
        
        // Send notification to STUDENT
        insertNotification($conn, $session['student_id'], "Session Auto-Completed", 
            "Your {$session['language']} session has been automatically completed. If you have any issues, please contact support.",
            "auto_completed", "booking_detail.php?id={$session['booking_id']}");
        
        // Send notification to TUTOR
        insertNotification($conn, $session['tutor_id'], "Session Auto-Completed", 
            "Your {$session['language']} session has been automatically completed. Payment will be processed.",
            "auto_completed", "tutor_booking_detail.php?id={$session['booking_id']}");
        
        // Send email to STUDENT
        sendAutoCompleteEmail($session, 'student');
        
        // Send email to TUTOR
        sendAutoCompleteEmail($session, 'tutor');
        
        echo "Auto-completed booking #{$session['booking_id']}\n";
    }
}

echo "Total auto-completed: $auto_completed_count\n";

function sendAutoCompleteEmail($session, $recipient) {
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
        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true); 
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        
        if ($recipient === 'student') {
            $mail->addAddress($session['student_email'], $session['student_name']);
        
            $mail->Subject = '=?UTF-8?B?' . base64_encode('Session Auto-Completed - Kyoshi') . '?=';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <h2 style='color:#E75A9B;'>Session Auto-Completed</h2>
                </div>
                <p>Dear <strong>{$session['student_name']}</strong>,</p>
                <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['tutor_name']}</strong> on <strong>$date at $time</strong> has been <strong>automatically completed</strong>.</p>
                <div style='background:#fff3cd;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #ffc107;'>
                    <p style='margin:0;color:#856404;'>
                        <strong>If you had any issues with this session, please report within 7 days.</strong>
                    </p>
                    <div style='text-align:center;margin-top:12px;'>
                        <a href='http://localhost/kyoshi/php/report_issue.php?booking_id={$session['booking_id']}' 
                           style='display:inline-block;padding:10px 20px;background:#ffc107;color:#333;border-radius:30px;text-decoration:none;font-weight:bold;'>
                            Report Issue →
                        </a>
                    </div>
                </div>
                <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Keep learning! 🚀</p>
            </div>
            ";
        } else {
            $mail->addAddress($session['tutor_email'], $session['tutor_name']);
            $mail->Subject = 'Session Auto-Completed - Kyoshi';
            $mail->Body = "
            <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
                <div style='text-align:center;'>
                    <img src='http://localhost/kyoshi/assets/img/logo.png' alt='Kyoshi' style='height:50px;'>
                    <h2 style='color:#E75A9B;'>Session Auto-Completed</h2>
                </div>
                <p>Dear <strong>{$session['tutor_name']}</strong>,</p>
                <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['student_name']}</strong> on <strong>$date at $time</strong> has been <strong>automatically completed</strong>.</p>
                <div style='background:#d4edda;padding:16px;border-radius:12px;margin:20px 0;border-left:4px solid #28a745;'>
                    <p style='margin:0;color:#155724;'>Payment will be processed to your account.</p>
                </div>
                <p style='font-size:12px;color:#999;text-align:center;margin-top:20px;'>Great teaching!</p>
            </div>
            ";
        }
        $mail->send();
    } catch (Exception $e) {
        error_log("Auto-complete email failed: " . $e->getMessage());
    }
}
?>