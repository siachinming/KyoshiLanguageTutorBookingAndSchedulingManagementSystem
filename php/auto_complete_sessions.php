<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
set_time_limit(0);
ini_set('memory_limit', '512M');

include __DIR__ . '/config.php';

if (!isset($conn) || $conn->connect_error) {
    die("Database connection failed\n");
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

// Get confirmed/accepted sessions that ended more than 24 hours ago
$stmt = $conn->prepare("
    SELECT 
        b.id as booking_id,
        b.student_id,
        b.tutor_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.learning_mode,
        b.status as current_status,
        s.fullname as student_name,
        s.email as student_email,
        t.fullname as tutor_name,
        t.email as tutor_email
    FROM bookings b
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id = t.id
    WHERE b.status IN ('confirmed', 'accepted')
    AND TIMESTAMP(b.booking_date, b.booking_time) < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    AND NOT EXISTS (
        SELECT 1 FROM session_completion sc 
        WHERE sc.booking_id = b.id AND sc.auto_completed = 1
    )
    LIMIT 100
");

$stmt->execute();
$sessions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$auto_completed_count = 0;
$skipped_disputed_count = 0;

echo "[" . date('Y-m-d H:i:s') . "] Found " . count($sessions) . " sessions to process\n";

foreach ($sessions as $session) {
    echo "\n--- Processing booking #{$session['booking_id']} ---\n";
    echo "  Current status: {$session['current_status']}\n";
    
    // CRITICAL: Skip if booking is already disputed
    if ($session['current_status'] === 'disputed') {
        echo "  ⏭ SKIPPED: Booking status is 'disputed' - cannot auto-complete\n";
        $skipped_disputed_count++;
        continue;
    }
    
    // Check attendance from meeting_logs (for notification purposes only)
    $meetingStmt = $conn->prepare("
        SELECT 
            COUNT(CASE WHEN participant_role = 'student' THEN 1 END) as student_joined,
            COUNT(CASE WHEN participant_role = 'tutor' THEN 1 END) as tutor_joined
        FROM meeting_logs
        WHERE booking_id = ?
    ");
    $meetingStmt->bind_param("i", $session['booking_id']);
    $meetingStmt->execute();
    $meetingResult = $meetingStmt->get_result()->fetch_assoc();
    
    $studentJoined = ($meetingResult && $meetingResult['student_joined'] > 0);
    $tutorJoined = ($meetingResult && $meetingResult['tutor_joined'] > 0);
    
    echo "  Student joined: " . ($studentJoined ? "✅ YES" : "❌ NO") . "\n";
    echo "  Tutor joined: " . ($tutorJoined ? "✅ YES" : "❌ NO") . "\n";
    
    // AUTO-COMPLETE REGARDLESS OF ATTENDANCE
    $updateStmt = $conn->prepare("
        UPDATE bookings 
        SET status = 'completed', 
            completed_at = NOW(),
            auto_completed = 1
        WHERE id = ? AND status IN ('confirmed', 'accepted')
    ");
    $updateStmt->bind_param("i", $session['booking_id']);
    $updateStmt->execute();
    
    if ($updateStmt->affected_rows > 0) {
        $auto_completed_count++;
        
        // Insert session completion record
        $completionStmt = $conn->prepare("
            INSERT INTO session_completion (booking_id, completed_at, auto_completed, status)
            VALUES (?, NOW(), 1, 'completed')
            ON DUPLICATE KEY UPDATE 
            completed_at = NOW(), 
            auto_completed = 1,
            status = 'completed'
        ");
        $completionStmt->bind_param("i", $session['booking_id']);
        $completionStmt->execute();
        
        echo "  ✅ AUTO-COMPLETED: Booking #{$session['booking_id']}\n";
        
        // Send different notifications based on attendance
        if ($studentJoined && $tutorJoined) {
            echo "  📧 Both attended - sending completion notifications\n";
            sendBothAttendedNotifications($conn, $session);
        } elseif ($tutorJoined && !$studentJoined) {
            echo "  📧 Only tutor attended - sending student no-show notifications\n";
            sendTutorOnlyNotifications($conn, $session);
        } elseif ($studentJoined && !$tutorJoined) {
            echo "  📧 Only student attended - sending tutor no-show notifications\n";
            sendStudentOnlyNotifications($conn, $session);
        } else {
            echo "  📧 Neither attended - sending no-show notifications\n";
            sendNeitherAttendedNotifications($conn, $session);
        }
    }
}

echo "\n=== SUMMARY ===\n";
echo "[" . date('Y-m-d H:i:s') . "] Auto-completed: $auto_completed_count\n";
echo "[" . date('Y-m-d H:i:s') . "] Skipped (already disputed): $skipped_disputed_count\n";
echo "[" . date('Y-m-d H:i:s') . "] Total processed: " . ($auto_completed_count + $skipped_disputed_count) . "\n";

function sendBothAttendedNotifications($conn, $session) {
    $date = date('d M Y', strtotime($session['booking_date']));
    $time = date('g:i A', strtotime($session['booking_time']));
    
    $studentMessage = "Your {$session['language']} session with {$session['tutor_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $studentMessage .= "✅ Both you and your tutor attended the session.\n\n";
    $studentMessage .= "If you had any issues, please report within 7 days.";
    
    $tutorMessage = "Your {$session['language']} session with {$session['student_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $tutorMessage .= "✅ Both you and the student attended the session.\n\n";
    $tutorMessage .= "Payment will be processed to your account within 3-5 business days.";
    
    insertNotification($conn, $session['student_id'], "Session Completed", $studentMessage, "completed", "booking_detail.php?id={$session['booking_id']}");
    insertNotification($conn, $session['tutor_id'], "Session Completed - Payment Processing", $tutorMessage, "completed", "tutor_booking_detail.php?id={$session['booking_id']}");
    
    sendEmail($session, 'both_attended');
}

function sendTutorOnlyNotifications($conn, $session) {
    $date = date('d M Y', strtotime($session['booking_date']));
    $time = date('g:i A', strtotime($session['booking_time']));
    
    $studentMessage = "⚠️ Your {$session['language']} session with {$session['tutor_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $studentMessage .= "❌ You did NOT attend the session, but your tutor did.\n\n";
    $studentMessage .= "The session has been marked as completed. If you have a valid reason for missing the session, please contact support within 7 days.";
    
    $tutorMessage = "Your {$session['language']} session with {$session['student_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $tutorMessage .= "✅ You attended the session, but the student did NOT show up.\n\n";
    $tutorMessage .= "Payment will still be processed to your account. Thank you for your commitment!";
    
    insertNotification($conn, $session['student_id'], "Session Completed - No Show", $studentMessage, "completed", "booking_detail.php?id={$session['booking_id']}");
    insertNotification($conn, $session['tutor_id'], "Session Completed - Student No Show", $tutorMessage, "completed", "tutor_booking_detail.php?id={$session['booking_id']}");
    
    sendEmail($session, 'tutor_only');
}

function sendStudentOnlyNotifications($conn, $session) {
    $date = date('d M Y', strtotime($session['booking_date']));
    $time = date('g:i A', strtotime($session['booking_time']));
    
    $studentMessage = "Your {$session['language']} session with {$session['tutor_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $studentMessage .= "✅ You attended the session, but your tutor did NOT show up.\n\n";
    $studentMessage .= "You will receive a full refund. Please contact support if you have any questions.";
    
    $tutorMessage = "⚠️ Your {$session['language']} session with {$session['student_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $tutorMessage .= "❌ You did NOT attend the session, but the student did.\n\n";
    $tutorMessage .= "This may affect your tutor rating and payment. Please contact support to explain your absence.";
    
    insertNotification($conn, $session['student_id'], "Session Completed - Tutor No Show", $studentMessage, "completed", "booking_detail.php?id={$session['booking_id']}");
    insertNotification($conn, $session['tutor_id'], "Session Completed - Your No Show", $tutorMessage, "warning", "tutor_booking_detail.php?id={$session['booking_id']}");
    
    sendEmail($session, 'student_only');
}

function sendNeitherAttendedNotifications($conn, $session) {
    $date = date('d M Y', strtotime($session['booking_date']));
    $time = date('g:i A', strtotime($session['booking_time']));
    
    $studentMessage = "Your {$session['language']} session with {$session['tutor_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $studentMessage .= "❌ Neither you nor your tutor attended the session.\n\n";
    $studentMessage .= "Please contact support if you believe this is an error or if you wish to reschedule.";
    
    $tutorMessage = "Your {$session['language']} session with {$session['student_name']} on {$date} at {$time} has been automatically completed.\n\n";
    $tutorMessage .= "❌ Neither you nor the student attended the session.\n\n";
    $tutorMessage .= "No payment will be processed. Please contact support if you have any questions.";
    
    insertNotification($conn, $session['student_id'], "Session Completed - No Show", $studentMessage, "completed", "booking_detail.php?id={$session['booking_id']}");
    insertNotification($conn, $session['tutor_id'], "Session Completed - No Show", $tutorMessage, "warning", "tutor_booking_detail.php?id={$session['booking_id']}");
    
    sendEmail($session, 'neither');
}

function insertNotification($conn, $userId, $title, $message, $type, $link) {
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, 0, NOW())");
    if ($stmt) {
        $stmt->bind_param("issss", $userId, $title, $message, $type, $link);
        $stmt->execute();
    }
}

function sendEmail($session, $scenario) {
    if (!defined('SMTP_USER') || !defined('SMTP_PASS')) {
        return;
    }
    
    $date = date('l, d F Y', strtotime($session['booking_date']));
    $time = date('g:i A', strtotime($session['booking_time']));
    
    // Send email to student
    $mailStudent = new PHPMailer(true);
    try {
        $mailStudent->isSMTP();
        $mailStudent->Host = 'smtp.gmail.com';
        $mailStudent->SMTPAuth = true;
        $mailStudent->Username = SMTP_USER;
        $mailStudent->Password = SMTP_PASS;
        $mailStudent->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailStudent->Port = 587;
        $mailStudent->setFrom(SMTP_USER, 'Kyoshi');
        $mailStudent->addAddress($session['student_email'], $session['student_name']);
        $mailStudent->isHTML(true);
        $mailStudent->Subject = 'Session Auto-Completed - Kyoshi';
        
        $mailStudent->Body = getStudentEmailBody($session, $scenario, $date, $time);
        $mailStudent->send();
    } catch (Exception $e) {
        error_log("Student email failed: " . $e->getMessage());
    }
    
    // Send email to tutor
    $mailTutor = new PHPMailer(true);
    try {
        $mailTutor->isSMTP();
        $mailTutor->Host = 'smtp.gmail.com';
        $mailTutor->SMTPAuth = true;
        $mailTutor->Username = SMTP_USER;
        $mailTutor->Password = SMTP_PASS;
        $mailTutor->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mailTutor->Port = 587;
        $mailTutor->setFrom(SMTP_USER, 'Kyoshi');
        $mailTutor->addAddress($session['tutor_email'], $session['tutor_name']);
        $mailTutor->isHTML(true);
        $mailTutor->Subject = 'Session Auto-Completed - Kyoshi';
        
        $mailTutor->Body = getTutorEmailBody($session, $scenario, $date, $time);
        $mailTutor->send();
    } catch (Exception $e) {
        error_log("Tutor email failed: " . $e->getMessage());
    }
}

function getStudentEmailBody($session, $scenario, $date, $time) {
    if ($scenario === 'both_attended') {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#28a745;'>Session Completed ✓</h2>
            <p>Dear <strong>{$session['student_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['tutor_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>✅ Both you and your tutor attended the session.</p>
            <p>If you had any issues, please report within 7 days.</p>
        </div>";
    } elseif ($scenario === 'tutor_only') {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#ffc107;'>Session Completed - You Missed</h2>
            <p>Dear <strong>{$session['student_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['tutor_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>❌ You did NOT attend, but your tutor did.</p>
            <p>If you have a valid reason, please contact support within 7 days.</p>
        </div>";
    } elseif ($scenario === 'student_only') {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#dc2626;'>Session Completed - Tutor Missed</h2>
            <p>Dear <strong>{$session['student_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['tutor_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>✅ You attended, but your tutor did NOT show up.</p>
            <p>You will receive a full refund.</p>
        </div>";
    } else {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#999;'>Session Completed - No Show</h2>
            <p>Dear <strong>{$session['student_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['tutor_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>❌ Neither you nor your tutor attended.</p>
            <p>Please contact support if you wish to reschedule.</p>
        </div>";
    }
}

function getTutorEmailBody($session, $scenario, $date, $time) {
    if ($scenario === 'both_attended') {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#28a745;'>Session Completed - Payment Processing</h2>
            <p>Dear <strong>{$session['tutor_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>✅ Both you and the student attended.</p>
            <p>Payment will be processed within 3-5 business days.</p>
        </div>";
    } elseif ($scenario === 'tutor_only') {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#28a745;'>Session Completed - Student No Show</h2>
            <p>Dear <strong>{$session['tutor_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>✅ You attended, but the student did NOT show up.</p>
            <p>Payment will still be processed. Thank you for your commitment!</p>
        </div>";
    } elseif ($scenario === 'student_only') {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#dc2626;'>Session Completed - You Missed</h2>
            <p>Dear <strong>{$session['tutor_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>❌ You did NOT attend, but the student did.</p>
            <p>No payment will be processed. Please contact support to explain your absence.</p>
        </div>";
    } else {
        return "
        <div style='font-family:Segoe UI,sans-serif;max-width:550px;margin:auto;border:1px solid #e0e0e0;border-radius:16px;padding:24px;background:#fff;'>
            <h2 style='color:#999;'>Session Completed - No Show</h2>
            <p>Dear <strong>{$session['tutor_name']}</strong>,</p>
            <p>Your <strong>{$session['language']}</strong> session with <strong>{$session['student_name']}</strong> on <strong>$date at $time</strong> has been completed.</p>
            <p>❌ Neither you nor the student attended.</p>
            <p>No payment will be processed.</p>
        </div>";
    }
}
?>