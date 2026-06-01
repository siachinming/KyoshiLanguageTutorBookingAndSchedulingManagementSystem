<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
set_time_limit(0);
ini_set('memory_limit', '512M');

include __DIR__ . '/config.php';

if (!isset($conn) || $conn->connect_error) {
    error_log("[Kyoshi] auto_complete_sessions: DB connection failed");
    exit(1);
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require __DIR__ . '/../vendor/autoload.php';

date_default_timezone_set('Asia/Kuala_Lumpur');

error_log("[Kyoshi] auto_complete_sessions started at " . date('Y-m-d H:i:s'));

// ── Fetch sessions that ended 24+ hours ago and not yet auto-completed ──
$stmt = $conn->prepare("
    SELECT
        b.id            AS booking_id,
        b.student_id,
        b.tutor_id,
        b.language,
        b.booking_date,
        b.booking_time,
        b.learning_mode,
        b.status        AS current_status,
        s.fullname      AS student_name,
        s.email         AS student_email,
        t.fullname      AS tutor_name,
        t.email         AS tutor_email
    FROM bookings b
    JOIN users s ON b.student_id = s.id
    JOIN users t ON b.tutor_id   = t.id
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
$stmt->close();

$completed = 0;
$skipped   = 0;

foreach ($sessions as $session) {
    $bookingId = $session['booking_id'];

    // Skip disputed bookings
    if ($session['current_status'] === 'disputed') {
        error_log("[Kyoshi] Booking #$bookingId skipped — disputed");
        $skipped++;
        continue;
    }

    // Check attendance from meeting_logs
    $meetStmt = $conn->prepare("
        SELECT
            COUNT(CASE WHEN participant_role = 'student' THEN 1 END) AS student_joined,
            COUNT(CASE WHEN participant_role = 'tutor'   THEN 1 END) AS tutor_joined
        FROM meeting_logs
        WHERE booking_id = ?
    ");
    $meetStmt->bind_param("i", $bookingId);
    $meetStmt->execute();
    $meet = $meetStmt->get_result()->fetch_assoc();
    $meetStmt->close();

    $studentJoined = $meet && $meet['student_joined'] > 0;
    $tutorJoined   = $meet && $meet['tutor_joined']   > 0;

    // Mark booking as completed
    $upd = $conn->prepare("
        UPDATE bookings
        SET status = 'completed', completed_at = NOW(), auto_completed = 1
        WHERE id = ? AND status IN ('confirmed', 'accepted')
    ");
    $upd->bind_param("i", $bookingId);
    $upd->execute();

    if ($upd->affected_rows === 0) {
        $upd->close();
        continue; // already changed by something else
    }
    $upd->close();

    // Insert session_completion record
    $comp = $conn->prepare("
        INSERT INTO session_completion (booking_id, completed_at, auto_completed, status)
        VALUES (?, NOW(), 1, 'completed')
        ON DUPLICATE KEY UPDATE completed_at = NOW(), auto_completed = 1, status = 'completed'
    ");
    $comp->bind_param("i", $bookingId);
    $comp->execute();
    $comp->close();

    $completed++;
    error_log("[Kyoshi] Booking #$bookingId auto-completed (student=" . ($studentJoined?'Y':'N') . " tutor=" . ($tutorJoined?'Y':'N') . ")");

    // Send notifications + emails based on attendance
    if ($studentJoined && $tutorJoined) {
        notifyBothAttended($conn, $session);
    } elseif ($tutorJoined && !$studentJoined) {
        notifyTutorOnly($conn, $session);
    } elseif ($studentJoined && !$tutorJoined) {
        notifyStudentOnly($conn, $session);
    } else {
        notifyNeitherAttended($conn, $session);
    }
}

error_log("[Kyoshi] auto_complete_sessions done — completed: $completed, skipped: $skipped");

// ════════════════════════════════════════════════
//  NOTIFICATION HELPERS
// ════════════════════════════════════════════════

function addNotification($conn, $userId, $title, $message, $type, $link) {
    $s = $conn->prepare("
        INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
        VALUES (?, ?, ?, ?, ?, 0, NOW())
    ");
    if ($s) {
        $s->bind_param("issss", $userId, $title, $message, $type, $link);
        $s->execute();
        $s->close();
    }
}

function notifyBothAttended($conn, $s) {
    $d = fmtDate($s['booking_date']); $t = fmtTime($s['booking_time']);
    addNotification($conn, $s['student_id'],
        "Session Completed ✓",
        "Your {$s['language']} session with {$s['tutor_name']} on $d at $t has been completed. Both you and your tutor attended. If you had any issues, please report within 7 days.",
        "completed", "booking_detail.php?id={$s['booking_id']}");
    addNotification($conn, $s['tutor_id'],
        "Session Completed — Payment Processing",
        "Your {$s['language']} session with {$s['student_name']} on $d at $t has been completed. Both attended. Payment will be processed within 3–5 business days.",
        "completed", "tutor_booking_detail.php?id={$s['booking_id']}");
    sendEmails($s, 'both_attended');
}

function notifyTutorOnly($conn, $s) {
    $d = fmtDate($s['booking_date']); $t = fmtTime($s['booking_time']);
    addNotification($conn, $s['student_id'],
        "Session Completed — You Missed",
        "Your {$s['language']} session with {$s['tutor_name']} on $d at $t is completed. You did not attend but your tutor did. Contact support within 7 days if you have a valid reason.",
        "warning", "booking_detail.php?id={$s['booking_id']}");
    addNotification($conn, $s['tutor_id'],
        "Session Completed — Student No Show",
        "Your {$s['language']} session with {$s['student_name']} on $d at $t is completed. You attended but the student did not show up. Payment will still be processed.",
        "completed", "tutor_booking_detail.php?id={$s['booking_id']}");
    sendEmails($s, 'tutor_only');
}

function notifyStudentOnly($conn, $s) {
    $d = fmtDate($s['booking_date']); $t = fmtTime($s['booking_time']);
    addNotification($conn, $s['student_id'],
        "Session Completed — Tutor No Show",
        "Your {$s['language']} session with {$s['tutor_name']} on $d at $t is completed. You attended but your tutor did not show up. You will receive a full refund.",
        "completed", "booking_detail.php?id={$s['booking_id']}");
    addNotification($conn, $s['tutor_id'],
        "Session Completed — You Missed",
        "Your {$s['language']} session with {$s['student_name']} on $d at $t is completed. You did not attend but the student did. This may affect your rating. Please contact support.",
        "warning", "tutor_booking_detail.php?id={$s['booking_id']}");
    sendEmails($s, 'student_only');
}

function notifyNeitherAttended($conn, $s) {
    $d = fmtDate($s['booking_date']); $t = fmtTime($s['booking_time']);
    addNotification($conn, $s['student_id'],
        "Session Completed — No Show",
        "Your {$s['language']} session with {$s['tutor_name']} on $d at $t is completed. Neither you nor your tutor attended. Contact support to reschedule.",
        "warning", "booking_detail.php?id={$s['booking_id']}");
    addNotification($conn, $s['tutor_id'],
        "Session Completed — No Show",
        "Your {$s['language']} session with {$s['student_name']} on $d at $t is completed. Neither party attended. No payment will be processed.",
        "warning", "tutor_booking_detail.php?id={$s['booking_id']}");
    sendEmails($s, 'neither');
}

// ════════════════════════════════════════════════
//  EMAIL HELPERS
// ════════════════════════════════════════════════

function sendEmails($session, $scenario) {
    if (!defined('SMTP_USER') || !defined('SMTP_PASS')) return;

    $d = date('l, d F Y', strtotime($session['booking_date']));
    $t = date('g:i A',    strtotime($session['booking_time']));

    sendOneMail($session['student_email'], $session['student_name'], studentBody($session, $scenario, $d, $t));
    sendOneMail($session['tutor_email'],   $session['tutor_name'],   tutorBody($session,   $scenario, $d, $t));
}

function sendOneMail($toEmail, $toName, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        $mail->setFrom(SMTP_USER, 'Kyoshi');
        $mail->addAddress($toEmail, $toName);
        $mail->isHTML(true);
        $mail->Subject = 'Session Auto-Completed — Kyoshi';
        $mail->Body    = $body;
        $mail->send();
    } catch (Exception $e) {
        error_log("[Kyoshi] Email failed to $toEmail: " . $e->getMessage());
    }
}

function emailWrap($heading, $headingColor, $studentOrTutor, $lang, $otherParty, $date, $time, $body) {
    return "
    <div style='font-family:Segoe UI,sans-serif;max-width:560px;margin:auto;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.08);'>
        <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:28px 32px;'>
            <h2 style='margin:0;color:white;font-size:20px;'>$heading</h2>
        </div>
        <div style='padding:28px 32px;background:#fff;'>
            <p style='font-size:15px;color:#342635;'>Dear <strong>$studentOrTutor</strong>,</p>
            <p style='font-size:14px;color:#7B6178;'>Your <strong>$lang</strong> session with <strong>$otherParty</strong> on <strong>$date at $time</strong> has been automatically completed.</p>
            <div style='background:#FFF1F6;border:1px solid rgba(231,90,155,.2);border-radius:12px;padding:16px;margin:20px 0;font-size:14px;color:#342635;line-height:1.7;'>
                $body
            </div>
            <p style='font-size:12px;color:#aaa;margin-top:24px;'>This is an automated message from Kyoshi. Do not reply to this email.</p>
        </div>
    </div>";
}

function studentBody($s, $scenario, $d, $t) {
    switch ($scenario) {
        case 'both_attended':
            return emailWrap('Session Completed ✓', '#28a745', $s['student_name'], $s['language'], $s['tutor_name'], $d, $t,
                '✅ Both you and your tutor attended.<br>If you had any issues, please report within 7 days.');
        case 'tutor_only':
            return emailWrap('Session Completed — You Missed', '#ffc107', $s['student_name'], $s['language'], $s['tutor_name'], $d, $t,
                '❌ You did not attend, but your tutor did.<br>If you have a valid reason, please contact support within 7 days.');
        case 'student_only':
            return emailWrap('Session Completed — Tutor No Show', '#dc2626', $s['student_name'], $s['language'], $s['tutor_name'], $d, $t,
                '✅ You attended, but your tutor did not show up.<br>You will receive a full refund. Contact support if you have questions.');
        default:
            return emailWrap('Session Completed — No Show', '#999', $s['student_name'], $s['language'], $s['tutor_name'], $d, $t,
                '❌ Neither you nor your tutor attended.<br>Please contact support if you wish to reschedule.');
    }
}

function tutorBody($s, $scenario, $d, $t) {
    switch ($scenario) {
        case 'both_attended':
            return emailWrap('Session Completed — Payment Processing', '#28a745', $s['tutor_name'], $s['language'], $s['student_name'], $d, $t,
                '✅ Both you and the student attended.<br>Payment will be processed within 3–5 business days.');
        case 'tutor_only':
            return emailWrap('Session Completed — Student No Show', '#28a745', $s['tutor_name'], $s['language'], $s['student_name'], $d, $t,
                '✅ You attended, but the student did not show up.<br>Payment will still be processed. Thank you for your commitment!');
        case 'student_only':
            return emailWrap('Session Completed — You Missed', '#dc2626', $s['tutor_name'], $s['language'], $s['student_name'], $d, $t,
                '❌ You did not attend, but the student did.<br>No payment will be processed. Please contact support to explain your absence.');
        default:
            return emailWrap('Session Completed — No Show', '#999', $s['tutor_name'], $s['language'], $s['student_name'], $d, $t,
                '❌ Neither you nor the student attended.<br>No payment will be processed.');
    }
}

function fmtDate($d) { return date('d M Y', strtotime($d)); }
function fmtTime($t) { return date('g:i A', strtotime($t)); }