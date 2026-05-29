<?php
// This file should be run by a cron job every 15 minutes
// Or can be triggered manually

session_start();
include 'config.php';
include 'insert_notification.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

function sendMeetingLinkReminder($conn, $booking_id) {
    // Get booking details
    $stmt = $conn->prepare("
        SELECT b.*, 
               tutor.fullname as tutor_name, tutor.email as tutor_email, tutor.id as tutor_id,
               student.fullname as student_name, student.email as student_email, student.id as student_id
        FROM bookings b
        JOIN users tutor ON b.tutor_id = tutor.id
        JOIN users student ON b.student_id = student.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    
    if (!$booking) return;
    
    // Only send reminder if session is confirmed and meeting link is missing
    if ($booking['status'] === 'confirmed' && empty($booking['meeting_link']) && $booking['learning_mode'] === 'online') {
        
        // ========== IN-APP NOTIFICATION FOR TUTOR ==========
        insertNotification(
            $conn,
            $booking['tutor_id'],
            'Meeting Link Required',
            "Your {$booking['language']} session with {$booking['student_name']} on " . date('d M Y', strtotime($booking['booking_date'])) . " needs a meeting link. Please add it before the session.",
            'reminder',
            "tutor_booking_detail.php?id={$booking_id}"
        );
        
        // ========== EMAIL TO TUTOR ==========
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
            $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
            $mail->isHTML(true);
            $mail->Subject = '⚠️ Action Required: Add Meeting Link for Your Session - Kyoshi';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                    <div style='text-align: center;'>
                        <h1 style='color: #E75A9B;'>Meeting Link Missing!</h1>
                    </div>
                    <div style='background: white; border-radius: 16px; padding: 20px;'>
                        <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
                        <p>You have a session scheduled <strong>today</strong> but you haven't added the meeting link yet.</p>
                        <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                            <p><strong>Student:</strong> {$booking['student_name']}</p>
                            <p><strong>Language:</strong> {$booking['language']}</p>
                            <p><strong>Date:</strong> " . date('l, F j, Y', strtotime($booking['booking_date'])) . "</p>
                            <p><strong>Time:</strong> " . date('g:i A', strtotime($booking['booking_time'])) . "</p>
                        </div>
                        <p>Please add your meeting link immediately so the student can join.</p>
                        <div style='text-align: center; margin-top: 20px;'>
                            <a href='http://localhost/kyoshi/php/tutor_booking_detail.php?id={$booking_id}' 
                               style='display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; 
                                      text-decoration: none; border-radius: 30px;'>Add Meeting Link Now</a>
                        </div>
                    </div>
                </div>
            ";
            $mail->send();
            
            // Mark reminder as sent
            $updateStmt = $conn->prepare("UPDATE bookings SET link_reminder_sent = 1 WHERE id = ?");
            $updateStmt->bind_param("i", $booking_id);
            $updateStmt->execute();
            
        } catch (Exception $e) {
            error_log("Meeting link reminder failed: " . $mail->ErrorInfo);
        }
    }
}

function sendSessionReminder($conn, $booking_id, $minutes_before) {
    // Get booking details
    $stmt = $conn->prepare("
        SELECT b.*, 
               tutor.fullname as tutor_name, tutor.email as tutor_email, tutor.id as tutor_id,
               student.fullname as student_name, student.email as student_email, student.id as student_id
        FROM bookings b
        JOIN users tutor ON b.tutor_id = tutor.id
        JOIN users student ON b.student_id = student.id
        WHERE b.id = ?
    ");
    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking = $stmt->get_result()->fetch_assoc();
    
    if (!$booking) return;
    
    // ========== IN-APP NOTIFICATION FOR TUTOR ==========
    insertNotification(
        $conn,
        $booking['tutor_id'],
        'Session Starting Soon',
        "Your {$booking['language']} session with {$booking['student_name']} starts in {$minutes_before} minutes at " . date('g:i A', strtotime($booking['booking_time'])),
        'reminder',
        "tutor_booking_detail.php?id={$booking_id}"
    );
    
    // ========== IN-APP NOTIFICATION FOR STUDENT ==========
    insertNotification(
        $conn,
        $booking['student_id'],
        'Session Starting Soon',
        "Your {$booking['language']} session with {$booking['tutor_name']} starts in {$minutes_before} minutes at " . date('g:i A', strtotime($booking['booking_time'])),
        'reminder',
        "booking_detail.php?id={$booking_id}"
    );
    
    // ========== EMAIL TO TUTOR ==========
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
        $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
        $mail->isHTML(true);
        
        $sessionType = $booking['learning_mode'] === 'online' ? 'Online Meeting' : 'Face-to-Face Session';
        $meetingInfo = '';
        
        if ($booking['learning_mode'] === 'online') {
            $meetingInfo = $booking['meeting_link'] 
                ? "<p><strong>Meeting Link:</strong> <a href='{$booking['meeting_link']}' style='color:#E75A9B;'>{$booking['meeting_link']}</a></p>"
                : "<p style='color: #dc2626;'><strong>No meeting link added yet! Please add it now.</strong></p>";
        } else {
            $meetingInfo = $booking['meeting_location']
                ? "<p><strong>Location:</strong> {$booking['meeting_location']}</p>"
                : "<p style='color: #dc2626;'><strong>No location provided! Please contact the student.</strong></p>";
        }
        
        $mail->Subject = "Session Reminder: {$booking['language']} in {$minutes_before} minutes - Kyoshi";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                <div style='text-align: center;'>
                    <h1 style='color: #E75A9B;'>Session Reminder</h1>
                    <p style='font-size: 14px;'>Your session starts in <strong>{$minutes_before} minutes</strong></p>
                </div>
                <div style='background: white; border-radius: 16px; padding: 20px;'>
                    <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
                    <p>This is a reminder for your upcoming {$booking['language']} session.</p>
                    <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                        <p><strong>Student:</strong> {$booking['student_name']}</p>
                        <p><strong>Session Type:</strong> {$sessionType}</p>
                        <p><strong>Date:</strong> " . date('l, F j, Y', strtotime($booking['booking_date'])) . "</p>
                        <p><strong>Time:</strong> " . date('g:i A', strtotime($booking['booking_time'])) . "</p>
                        {$meetingInfo}
                    </div>
                    <div style='text-align: center; margin-top: 20px;'>
                        <a href='http://localhost/kyoshi/php/tutor_booking_detail.php?id={$booking_id}' 
                           style='display: inline-block; padding: 12px 30px; background: linear-gradient(135deg, #E75A9B, #F28AB2); color: white; 
                                  text-decoration: none; border-radius: 30px;'>View Session Details</a>
                    </div>
                </div>
            </div>
        ";
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Tutor reminder failed: " . $mail->ErrorInfo);
    }
    
    // ========== EMAIL TO STUDENT ==========
    $mail2 = new PHPMailer(true);
    
    try {
        $mail2->isSMTP();
        $mail2->Host = 'smtp.gmail.com';
        $mail2->SMTPAuth = true;
        $mail2->Username = SMTP_USER;
        $mail2->Password = SMTP_PASS;
        $mail2->SMTPSecure = 'tls';
        $mail2->Port = 587;
        $mail2->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail2->addAddress($booking['student_email'], $booking['student_name']);
        $mail2->isHTML(true);
        
        $sessionType = $booking['learning_mode'] === 'online' ? 'Online Meeting' : 'Face-to-Face Session';
        $joinButton = '';
        
        if ($booking['learning_mode'] === 'online' && !empty($booking['meeting_link'])) {
            $joinButton = "
                <div style='text-align: center; margin-top: 20px;'>
                    <a href='join_meeting.php?booking_id={$booking_id}&link=" . urlencode($booking['meeting_link']) . "' 
                       style='display: inline-block; padding: 12px 30px; background: #28a745; color: white; 
                              text-decoration: none; border-radius: 30px;'>Join Meeting Now</a>
                </div>
            ";
        }
        
        $mail2->Subject = "Session Reminder: {$booking['language']} in {$minutes_before} minutes - Kyoshi";
        $mail2->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                <div style='text-align: center;'>
                    <h1 style='color: #E75A9B;'>Session Reminder</h1>
                    <p style='font-size: 14px;'>Your session starts in <strong>{$minutes_before} minutes</strong></p>
                </div>
                <div style='background: white; border-radius: 16px; padding: 20px;'>
                    <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                    <p>This is a reminder for your upcoming {$booking['language']} session.</p>
                    <div style='background: #e8f4f8; border-radius: 12px; padding: 15px; margin: 20px 0;'>
                        <p><strong>Tutor:</strong> {$booking['tutor_name']}</p>
                        <p><strong>Session Type:</strong> {$sessionType}</p>
                        <p><strong>Date:</strong> " . date('l, F j, Y', strtotime($booking['booking_date'])) . "</p>
                        <p><strong>Time:</strong> " . date('g:i A', strtotime($booking['booking_time'])) . "</p>
                        " . ($booking['learning_mode'] === 'online' && !empty($booking['meeting_link']) ? "<p><strong>Meeting Link:</strong> <a href='" . $booking['meeting_link'] . "' style='color:#E75A9B;'>Join Meeting</a></p>" : "") . "
                        " . ($booking['learning_mode'] === 'face_to_face' && !empty($booking['meeting_location']) ? "<p><strong>Location:</strong> {$booking['meeting_location']}</p>" : "") . "
                    </div>
                    {$joinButton}
                    <div style='text-align: center; margin-top: 20px;'>
                        <a href='http://localhost/kyoshi/php/booking_detail.php?id={$booking_id}' 
                           style='display: inline-block; padding: 12px 30px; background: #1d3156; color: white; 
                                  text-decoration: none; border-radius: 30px;'>View Session Details</a>
                    </div>
                </div>
            </div>
        ";
        $mail2->send();
        
        // Mark reminder as sent
        $updateStmt = $conn->prepare("UPDATE bookings SET reminder_sent = 1 WHERE id = ?");
        $updateStmt->bind_param("i", $booking_id);
        $updateStmt->execute();
        
    } catch (Exception $e) {
        error_log("Student reminder failed: " . $mail2->ErrorInfo);
    }
}

// Add columns to bookings table if not exists
$conn->query("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS reminder_sent TINYINT DEFAULT 0");
$conn->query("ALTER TABLE bookings ADD COLUMN IF NOT EXISTS link_reminder_sent TINYINT DEFAULT 0");

// ========== CRON JOB SECTION ==========
// This script should be run every 15 minutes via cron job

// 1. Send meeting link reminders (24 hours before session, if no link)
$stmt = $conn->prepare("
    SELECT b.id 
    FROM bookings b
    WHERE b.status = 'confirmed'
    AND b.learning_mode = 'online'
    AND (b.meeting_link IS NULL OR b.meeting_link = '')
    AND (b.link_reminder_sent = 0 OR b.link_reminder_sent IS NULL)
    AND b.booking_date <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
    AND b.booking_date >= CURDATE()
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    sendMeetingLinkReminder($conn, $row['id']);
}

// 2. Send 15-minute reminders
$stmt = $conn->prepare("
    SELECT b.id, TIMESTAMPDIFF(MINUTE, NOW(), CONCAT(b.booking_date, ' ', b.booking_time)) as minutes_until
    FROM bookings b
    WHERE b.status = 'confirmed'
    AND (b.reminder_sent = 0 OR b.reminder_sent IS NULL)
    AND CONCAT(b.booking_date, ' ', b.booking_time) BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 20 MINUTE)
");
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    sendSessionReminder($conn, $row['id'], $row['minutes_until']);
}

?>