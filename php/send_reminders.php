<?php
// This file should be run by a cron job every 15 minutes
// Or can be triggered manually

// Add error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors for cron
ini_set('log_errors', 1);

// Log file for debugging
$logFile = __DIR__ . '/../logs/send_reminders.log';
$logDir = dirname($logFile);
if (!file_exists($logDir)) {
    mkdir($logDir, 0777, true);
}

function writeLog($message) {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

writeLog("Reminder script started");

// Don't use session_start() for cron jobs
// session_start(); // <-- COMMENT THIS OUT OR REMOVE

include 'config.php';

// Check if connection exists
if (!$conn || $conn->connect_error) {
    writeLog("ERROR: Database connection failed: " . ($conn->connect_error ?? "Unknown error"));
    echo "Database connection failed\n";
    exit(1);
}

// Check if insert_notification.php exists
if (!file_exists('insert_notification.php')) {
    writeLog("WARNING: insert_notification.php not found. Creating fallback function.");
    
    // Create fallback function if file doesn't exist
    if (!function_exists('insertNotification')) {
        function insertNotification($conn, $user_id, $title, $message, $type, $link) {
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
            if ($stmt) {
                $stmt->bind_param("issss", $user_id, $title, $message, $type, $link);
                return $stmt->execute();
            }
            return false;
        }
    }
} else {
    include 'insert_notification.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Check if vendor/autoload.php exists
$autoloadPath = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    writeLog("ERROR: PHPMailer autoload not found at: $autoloadPath");
    echo "PHPMailer not installed. Run: composer require phpmailer/phpmailer\n";
    exit(1);
}
require $autoloadPath;


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
    
    if (!$booking) return false;
    
    // Only send reminder if session is confirmed and meeting link is missing
    if ($booking['status'] === 'confirmed' && empty($booking['meeting_link']) && $booking['learning_mode'] === 'online') {
        
        // ========== IN-APP NOTIFICATION FOR TUTOR ==========
        if (function_exists('insertNotification')) {
            insertNotification(
                $conn,
                $booking['tutor_id'],
                'Meeting Link Required',
                "Your {$booking['language']} session with {$booking['student_name']} on " . date('d M Y', strtotime($booking['booking_date'])) . " needs a meeting link. Please add it before the session.",
                'reminder',
                "tutor_booking_detail.php?id={$booking_id}"
            );
        }
        
        // ========== EMAIL TO TUTOR ==========
        $mail = new PHPMailer(true);
        
        try {
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
            $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
            $mail->isHTML(true);
            $mail->Subject = 'Action Required: Add Meeting Link for Your Session - Kyoshi';
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
                            <a href='http://kyoshitutor.site/php/tutor_booking_detail.php?id={$booking_id}' 
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
            
            writeLog("Meeting link reminder sent for booking #$booking_id");
            return true;
            
        } catch (Exception $e) {
            writeLog("Meeting link reminder failed for booking #$booking_id: " . $mail->ErrorInfo);
            error_log("Meeting link reminder failed: " . $mail->ErrorInfo);
        }
    }
    return false;
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
    
    if (!$booking) return false;
    
    // ========== IN-APP NOTIFICATION FOR TUTOR ==========
    if (function_exists('insertNotification')) {
        insertNotification(
            $conn,
            $booking['tutor_id'],
            'Session Starting Soon',
            "Your {$booking['language']} session with {$booking['student_name']} starts in {$minutes_before} minutes at " . date('g:i A', strtotime($booking['booking_time'])),
            'reminder',
            "tutor_booking_detail.php?id={$booking_id}"
        );
        
        insertNotification(
            $conn,
            $booking['student_id'],
            'Session Starting Soon',
            "Your {$booking['language']} session with {$booking['tutor_name']} starts in {$minutes_before} minutes at " . date('g:i A', strtotime($booking['booking_time'])),
            'reminder',
            "booking_detail.php?id={$booking_id}"
        );
    }
    
    $success = false;
    
// ========== EMAIL TO TUTOR - WITH DIRECT JOIN BUTTON ==========
$mail = new PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = SMTP_USER;
    $mail->Password = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $mail->addAddress($booking['tutor_email'], $booking['tutor_name']);
    $mail->isHTML(true);
    
    $sessionType = $booking['learning_mode'] === 'online' ? 'Online Meeting' : 'Face-to-Face Session';
    $meetingInfo = '';
    $tutorJoinButton = '';
    
    if ($booking['learning_mode'] === 'online') {
        if (!empty($booking['meeting_link'])) {
            $meetingInfo = "<p><strong>Meeting Link:</strong> <a href='{$booking['meeting_link']}' style='color:#E75A9B;'>{$booking['meeting_link']}</a></p>";
            // BIG GREEN BUTTON for tutor to join
            $tutorJoinButton = "
                <div style='text-align: center; margin: 25px 0;'>
                    <a href='http://kyoshitutor.site/php/join_meeting.php?booking_id={$booking_id}&link=" . urlencode($booking['meeting_link']) . "' 
                       style='display: inline-block; padding: 15px 45px; background: linear-gradient(135deg, #28a745, #20c997); color: white; 
                              text-decoration: none; border-radius: 50px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 15px rgba(40,167,69,0.3);'>
                        🎥 Click Here to Join Meeting
                    </a>
                    <p style='font-size: 12px; color: #666; margin-top: 10px;'>
                        ⚡ Your attendance will be automatically recorded when you click this button
                    </p>
                </div>
            ";
        } else {
            $meetingInfo = "<p style='color: #dc2626;'><strong>No meeting link added yet! Please add it now.</strong></p>";
        }
    } else {
        $meetingInfo = !empty($booking['meeting_location'])
            ? "<p><strong>Location:</strong> {$booking['meeting_location']}</p>"
            : "<p style='color: #dc2626;'><strong>No location provided! Please contact the student.</strong></p>";
    }
    
    $mail->Subject = "Session Reminder: {$booking['language']} in {$minutes_before} minutes - Kyoshi";
    $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
            <div style='text-align: center;'>
                <div style='background: #E75A9B; width: 60px; height: 60px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;'>
                    <span style='font-size: 30px; color: white;'>⏰</span>
                </div>
                <h1 style='color: #E75A9B; margin: 0;'>Session Reminder</h1>
                <p style='font-size: 14px; color: #666;'>Your session starts in <strong style='color: #E75A9B;'>{$minutes_before} minutes</strong></p>
            </div>
            <div style='background: white; border-radius: 16px; padding: 25px; margin-top: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);'>
                <p>Dear <strong>{$booking['tutor_name']}</strong>,</p>
                <p>This is a friendly reminder for your upcoming {$booking['language']} session.</p>
                <div style='background: linear-gradient(135deg, #f8f9fa, #fff); border-radius: 12px; padding: 15px; margin: 20px 0; border-left: 4px solid #E75A9B;'>
                    <p style='margin: 8px 0;'><strong>👨‍🎓 Student:</strong> {$booking['student_name']}</p>
                    <p style='margin: 8px 0;'><strong>📚 Language:</strong> {$booking['language']}</p>
                    <p style='margin: 8px 0;'><strong>📅 Date:</strong> " . date('l, F j, Y', strtotime($booking['booking_date'])) . "</p>
                    <p style='margin: 8px 0;'><strong>⏰ Time:</strong> " . date('g:i A', strtotime($booking['booking_time'])) . "</p>
                    <p style='margin: 8px 0;'><strong>💻 Session Type:</strong> {$sessionType}</p>
                    {$meetingInfo}
                </div>
                
                {$tutorJoinButton}
                
                <div style='background: #e8f4f8; border-radius: 12px; padding: 12px; margin-top: 20px;'>
                    <p style='margin: 0; font-size: 13px; color: #1d3156;'>
                        <strong>💡 Tip:</strong> Make sure you have a stable internet connection and your camera/mic are working.
                    </p>
                </div>
                
                <div style='text-align: center; margin-top: 25px;'>
                    <a href='http://kyoshitutor.site/php/tutor_booking_detail.php?id={$booking_id}' 
                       style='display: inline-block; padding: 10px 25px; background: #64748b; color: white; 
                              text-decoration: none; border-radius: 30px; font-size: 14px;'>View Session Details</a>
                </div>
            </div>
            <div style='text-align: center; margin-top: 20px; font-size: 12px; color: #999;'>
                <p>This is an automated reminder from Kyoshi.</p>
            </div>
        </div>
    ";
    $mail->send();
    $success = true;
    
} catch (Exception $e) {
    writeLog("Tutor reminder failed for booking #$booking_id: " . $mail->ErrorInfo);
}
    
    // ========== EMAIL TO STUDENT - WITH DIRECT JOIN BUTTON THAT LOGS ==========
    $mail2 = new PHPMailer(true);
    
    try {
        $mail2->isSMTP();
        $mail2->Host = 'smtp.gmail.com';
        $mail2->SMTPAuth = true;
        $mail2->Username = SMTP_USER;
        $mail2->Password = SMTP_PASS;
        $mail2->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail2->Port = 587;
        $mail2->setFrom('sohisabella87@gmail.com', 'Kyoshi');
        $mail2->addAddress($booking['student_email'], $booking['student_name']);
        $mail2->isHTML(true);
        
        $sessionType = $booking['learning_mode'] === 'online' ? 'Online Meeting' : 'Face-to-Face Session';
        $joinButton = '';
        
        // BIG GREEN BUTTON that logs meeting join
        if ($booking['learning_mode'] === 'online' && !empty($booking['meeting_link'])) {
            $joinButton = "
                <div style='text-align: center; margin: 25px 0;'>
                    <a href='http://kyoshitutor.site/php/join_meeting.php?booking_id={$booking_id}&link=" . urlencode($booking['meeting_link']) . "' 
                       style='display: inline-block; padding: 15px 45px; background: linear-gradient(135deg, #28a745, #20c997); color: white; 
                              text-decoration: none; border-radius: 50px; font-weight: bold; font-size: 18px; box-shadow: 0 4px 15px rgba(40,167,69,0.3);'>
                        🎥 Click Here to Join Meeting
                    </a>
                    <p style='font-size: 12px; color: #666; margin-top: 10px;'>
                        ⚡ Your attendance will be automatically recorded when you click this button
                    </p>
                </div>
            ";
        } elseif ($booking['learning_mode'] === 'online' && empty($booking['meeting_link'])) {
            $joinButton = "
                <div style='text-align: center; margin: 20px 0; padding: 15px; background: #fef3c7; border-radius: 12px;'>
                    <p style='color: #d97706; margin: 0;'>
                        ⚠️ Meeting link not yet available. The tutor will provide it before the session starts.
                    </p>
                </div>
            ";
        }
        
        // For face-to-face sessions, show location button
        $locationInfo = '';
        if ($booking['learning_mode'] === 'face_to_face' && !empty($booking['meeting_location'])) {
            $locationInfo = "
                <div style='text-align: center; margin: 20px 0;'>
                    <a href='https://www.google.com/maps/search/?api=1&query=" . urlencode($booking['meeting_location']) . "' 
                       target='_blank'
                       style='display: inline-block; padding: 12px 35px; background: #1d3156; color: white; 
                              text-decoration: none; border-radius: 50px; font-weight: bold; font-size: 16px;'>
                        📍 View Meeting Location on Map
                    </a>
                </div>
            ";
        }
        
        $mail2->Subject = "Session Reminder: {$booking['language']} in {$minutes_before} minutes - Kyoshi";
        $mail2->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: auto; background: #f9f9f9; border-radius: 20px; padding: 30px;'>
                <div style='text-align: center;'>
                    <div style='background: #E75A9B; width: 60px; height: 60px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 16px;'>
                        <span style='font-size: 30px; color: white;'>⏰</span>
                    </div>
                    <h1 style='color: #E75A9B; margin: 0;'>Session Reminder</h1>
                    <p style='font-size: 14px; color: #666;'>Your session starts in <strong style='color: #E75A9B;'>{$minutes_before} minutes</strong></p>
                </div>
                <div style='background: white; border-radius: 16px; padding: 25px; margin-top: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.05);'>
                    <p>Dear <strong>{$booking['student_name']}</strong>,</p>
                    <p>This is a friendly reminder for your upcoming {$booking['language']} session.</p>
                    <div style='background: linear-gradient(135deg, #f8f9fa, #fff); border-radius: 12px; padding: 15px; margin: 20px 0; border-left: 4px solid #E75A9B;'>
                        <p style='margin: 8px 0;'><strong>👨‍🏫 Tutor:</strong> {$booking['tutor_name']}</p>
                        <p style='margin: 8px 0;'><strong>📚 Language:</strong> {$booking['language']}</p>
                        <p style='margin: 8px 0;'><strong>📅 Date:</strong> " . date('l, F j, Y', strtotime($booking['booking_date'])) . "</p>
                        <p style='margin: 8px 0;'><strong>⏰ Time:</strong> " . date('g:i A', strtotime($booking['booking_time'])) . "</p>
                        <p style='margin: 8px 0;'><strong>💻 Session Type:</strong> {$sessionType}</p>
                    </div>
                    
                    {$joinButton}
                    {$locationInfo}
                    
                    <div style='background: #e8f4f8; border-radius: 12px; padding: 12px; margin-top: 20px;'>
                        <p style='margin: 0; font-size: 13px; color: #1d3156;'>
                            <strong>💡 Tip:</strong> Make sure you have a stable internet connection and your camera/mic are working.
                        </p>
                    </div>
                    
                    <div style='text-align: center; margin-top: 25px;'>
                        <a href='http://kyoshitutor.site/php/booking_detail.php?id={$booking_id}' 
                           style='display: inline-block; padding: 10px 25px; background: #64748b; color: white; 
                                  text-decoration: none; border-radius: 30px; font-size: 14px;'>View Session Details</a>
                    </div>
                </div>
                <div style='text-align: center; margin-top: 20px; font-size: 12px; color: #999;'>
                    <p>This is an automated reminder from Kyoshi.</p>
                </div>
            </div>
        ";
        $mail2->send();
        
        // Mark reminder as sent
        $updateStmt = $conn->prepare("UPDATE bookings SET reminder_sent = 1 WHERE id = ?");
        $updateStmt->bind_param("i", $booking_id);
        $updateStmt->execute();
        
        writeLog("Session reminder sent for booking #$booking_id ({$minutes_before} minutes before)");
        $success = true;
        
    } catch (Exception $e) {
        writeLog("Student reminder failed for booking #$booking_id: " . $mail2->ErrorInfo);
    }
    
    return $success;
}

// ========== CHECK AND ADD COLUMNS IF NOT EXISTS ==========
// Better way to check if columns exist
$columns = $conn->query("SHOW COLUMNS FROM bookings LIKE 'reminder_sent'");
if ($columns->num_rows == 0) {
    $conn->query("ALTER TABLE bookings ADD COLUMN reminder_sent TINYINT DEFAULT 0");
    writeLog("Added column: reminder_sent");
}

$columns = $conn->query("SHOW COLUMNS FROM bookings LIKE 'link_reminder_sent'");
if ($columns->num_rows == 0) {
    $conn->query("ALTER TABLE bookings ADD COLUMN link_reminder_sent TINYINT DEFAULT 0");
    writeLog("Added column: link_reminder_sent");
}

// ========== CRON JOB SECTION ==========
echo "Running reminder checks at " . date('Y-m-d H:i:s') . "\n";
writeLog("=== Running reminder checks ===");

$remindersSent = 0;
$linkRemindersSent = 0;

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
    if (sendMeetingLinkReminder($conn, $row['id'])) {
        $linkRemindersSent++;
    }
}
echo "📧 Meeting link reminders sent: $linkRemindersSent\n";
writeLog("Meeting link reminders sent: $linkRemindersSent");

// 2. Send 15-minute reminders (between 0-20 minutes before session)
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
    if (sendSessionReminder($conn, $row['id'], $row['minutes_until'])) {
        $remindersSent++;
    }
}
echo "📧 Session reminders sent: $remindersSent\n";
writeLog("Session reminders sent: $remindersSent");

echo "✅ Reminder script completed at " . date('Y-m-d H:i:s') . "\n";
writeLog("Reminder script completed");
?>