<?php
session_start();
include 'config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../vendor/autoload.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$studentID        = $_SESSION['user_id'];
$booking_id       = intval($_POST['booking_id'] ?? 0);
$new_date         = trim($_POST['booking_date'][0] ?? '');
$new_time         = trim($_POST['booking_time'][0] ?? '');
$language         = trim($_POST['language'] ?? '');
$learning_mode    = trim($_POST['mode'] ?? '');
$focus            = trim($_POST['focus'] ?? '');
$notes            = trim($_POST['notes'] ?? '');
$meeting_location = trim($_POST['location'] ?? '');
$next_ids_raw     = trim($_POST['next_ids'] ?? '');
$proficiency_level = trim($_POST['proficiency_level'] ?? 'beginner');

// Helper: redirect with error back to the booking detail page
function failWith($conn, $msg, $booking_id) {
    $_SESSION['error'] = $msg;
    // Log for debugging
    error_log("submit_reschedule.php ERROR [booking $booking_id]: $msg");
    header("Location: booking_detail.php?id=" . intval($booking_id));
    exit();
}

if (!$booking_id) {
    header("Location: booking_status.php");
    exit();
}

// ── 1. No duplicate pending request ──────────────────────────────────────────
$stmt = $conn->prepare("SELECT id FROM reschedule_requests WHERE booking_id = ? AND status = 'pending'");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    failWith($conn, "You already have a pending reschedule request. Please wait for the tutor's response.", $booking_id);
}
$stmt->close();

// ── 2. Fetch original booking + users ────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT b.booking_date, b.booking_time, b.tutor_id, b.status,
           t.fullname AS tutor_name, t.email AS tutor_email,
           s.fullname AS student_name, s.email AS student_email
    FROM bookings b
    JOIN users t ON b.tutor_id = t.id
    JOIN users s ON b.student_id = s.id
    WHERE b.id = ? AND b.student_id = ?
");
$stmt->bind_param("ii", $booking_id, $studentID);
$stmt->execute();
$original = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$original) {
    failWith($conn, "Booking not found.", $booking_id);
}

// ── 3. Must be confirmed ──────────────────────────────────────────────────────
if ($original['status'] !== 'confirmed') {
    failWith($conn, "This booking cannot be rescheduled (status: {$original['status']}).", $booking_id);
}

// ── 4. Original class must not have passed ────────────────────────────────────
$now       = new DateTime();
$classTime = new DateTime($original['booking_date'] . ' ' . $original['booking_time']);
if ($classTime < $now) {
    failWith($conn, "Cannot reschedule a class that has already passed.", $booking_id);
}

// ── 5. New date/time must differ from original ────────────────────────────────
if ($new_date === $original['booking_date'] && $new_time === $original['booking_time']) {
    failWith($conn, "New date/time cannot be the same as your current booking.", $booking_id);
}

// ── 6. New date/time must be in the future ────────────────────────────────────
$requestedTime = new DateTime($new_date . ' ' . $new_time);
if ($requestedTime < $now) {
    failWith($conn, "Cannot reschedule to a past date or time. Please select a future slot.", $booking_id);
}

// ── 7. Slot must not be taken by another student ──────────────────────────────
$stmt = $conn->prepare("
    SELECT id FROM bookings
    WHERE tutor_id = ? AND booking_date = ? AND booking_time = ?
      AND status IN ('pending','accepted','confirmed','rescheduled')
      AND id != ?
");
$stmt->bind_param("issi", $original['tutor_id'], $new_date, $new_time, $booking_id);
$stmt->execute();
if ($stmt->get_result()->num_rows > 0) {
    failWith($conn, "This time slot is already booked. Please choose another time.", $booking_id);
}
$stmt->close();

// ── 8. Insert reschedule request ──────────────────────────────────────────────
// Build INSERT dynamically so it works whether or not the column exists
// Check which columns the table actually has
$colCheck  = $conn->query("SHOW COLUMNS FROM reschedule_requests LIKE 'proficiency_level'");
$hasLevel  = ($colCheck && $colCheck->num_rows > 0);

if ($hasLevel) {
    $stmt = $conn->prepare("
        INSERT INTO reschedule_requests
            (booking_id, student_id, tutor_id, old_date, old_time,
             new_date, new_time, language, learning_mode, focus, notes,
             meeting_location, proficiency_level, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param(
        "iiissssssssss",
        $booking_id, $studentID, $original['tutor_id'],
        $original['booking_date'], $original['booking_time'],
        $new_date, $new_time,
        $language, $learning_mode, $focus, $notes,
        $meeting_location, $proficiency_level
    );
} else {
    $stmt = $conn->prepare("
        INSERT INTO reschedule_requests
            (booking_id, student_id, tutor_id, old_date, old_time,
             new_date, new_time, language, learning_mode, focus, notes,
             meeting_location, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
    ");
    $stmt->bind_param(
        "iiisssssssss",
        $booking_id, $studentID, $original['tutor_id'],
        $original['booking_date'], $original['booking_time'],
        $new_date, $new_time,
        $language, $learning_mode, $focus, $notes,
        $meeting_location
    );
}

if (!$stmt->execute()) {
    $err = $stmt->error;
    $stmt->close();
    failWith($conn, "Database error inserting reschedule request: $err", $booking_id);
}
$stmt->close();

// ── 9. Update booking status to 'rescheduled' ────────────────────────────────
$upd = $conn->prepare("UPDATE bookings SET status = 'rescheduled' WHERE id = ? AND student_id = ?");
$upd->bind_param("ii", $booking_id, $studentID);
if (!$upd->execute()) {
    error_log("submit_reschedule.php: failed to set status=rescheduled for booking $booking_id: " . $upd->error);
}
$upd->close();

// ── 10. In-app notification to tutor ─────────────────────────────────────────
$notifTitle = "New Reschedule Request";
$notifMsg   = "Student has requested to reschedule a session from "
            . date('d M Y, g:i A', strtotime($original['booking_date'] . ' ' . $original['booking_time']))
            . " to "
            . date('d M Y, g:i A', strtotime($new_date . ' ' . $new_time));

$notif = $conn->prepare(
    "INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
     VALUES (?, ?, ?, 'reschedule', 0, NOW())"
);
$notif->bind_param("iss", $original['tutor_id'], $notifTitle, $notifMsg);
$notif->execute();
$notif->close();

// ── 11. Email to tutor ────────────────────────────────────────────────────────
$oldDateFmt = date('l, F j, Y', strtotime($original['booking_date']));
$oldTimeFmt = date('g:i A',     strtotime($original['booking_time']));
$newDateFmt = date('l, F j, Y', strtotime($new_date));
$newTimeFmt = date('g:i A',     strtotime($new_time));
$modeLabel  = $learning_mode === 'online' ? 'Online' : 'Face to Face';
$reviewLink = "http://kyoshitutor.site/php/booking_requests.php";

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = 'tls';
    $mail->Port       = 587;

    $mail->setFrom('sohisabella87@gmail.com', 'Kyoshi');
    $mail->addAddress($original['tutor_email'], $original['tutor_name']);
    $mail->addReplyTo($original['student_email'], $original['student_name']);

    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'New Reschedule Request – Kyoshi';
    $mail->Body    = "
    <!DOCTYPE html><html><head><meta charset='UTF-8'></head>
    <body>
    <div style='font-family:Segoe UI,Arial,sans-serif;max-width:580px;margin:auto;
                background:#fff;border-radius:20px;overflow:hidden;
                box-shadow:0 8px 30px rgba(201,79,134,.12);'>
        <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);
                    padding:32px 32px 24px;text-align:center;'>
            <h1 style='margin:0;color:white;font-size:24px;'>New Reschedule Request</h1>
            <p style='margin:8px 0 0;color:rgba(255,255,255,.88);font-size:14px;'>
                A student wants to reschedule a session</p>
        </div>
        <div style='padding:28px 32px;'>
            <p style='font-size:15px;color:#342635;'>
                Hi <strong>" . htmlspecialchars($original['tutor_name']) . "</strong>,</p>
            <p style='font-size:14px;color:#7B6178;line-height:1.6;'>
                <strong>" . htmlspecialchars($original['student_name']) . "</strong>
                has requested to reschedule a session.</p>
            <div style='background:#FFF1F6;border:1px solid #fce7f3;
                        border-radius:16px;padding:20px;margin-bottom:20px;'>
                <p style='margin:0 0 12px;font-size:13px;font-weight:700;color:#C94F86;'>
                    Session Details</p>
                <p style='margin:6px 0;font-size:14px;color:#342635;'>
                    <strong>Language:</strong> " . htmlspecialchars($language) . "</p>
                <p style='margin:6px 0;font-size:14px;color:#342635;'>
                    <strong>Mode:</strong> $modeLabel</p>
                <p style='margin:6px 0;font-size:14px;color:#342635;'>
                    <strong>Original:</strong> $oldDateFmt at $oldTimeFmt</p>
                <p style='margin:6px 0;font-size:14px;color:#342635;'>
                    <strong>Requested:</strong> $newDateFmt at $newTimeFmt</p>
                " . (!empty($focus) ? "<p style='margin:6px 0;font-size:14px;color:#342635;'>
                    <strong>Focus:</strong> " . htmlspecialchars($focus) . "</p>" : "") . "
                " . (!empty($notes) ? "<p style='margin:6px 0;font-size:14px;color:#342635;'>
                    <strong>Notes:</strong> " . htmlspecialchars($notes) . "</p>" : "") . "
            </div>
            <div style='text-align:center;'>
                <a href='$reviewLink'
                   style='display:inline-block;padding:14px 32px;
                          background:linear-gradient(135deg,#E75A9B,#F28AB2);
                          color:white;border-radius:999px;text-decoration:none;
                          font-weight:700;font-size:14px;'>
                    Review Reschedule Request
                </a>
            </div>
            <p style='margin:24px 0 0;font-size:12px;color:#9080a0;
                      text-align:center;line-height:1.6;'>
                Please respond before the original booking date.</p>
        </div>
        <div style='background:#FFF1F6;padding:16px 32px;
                    text-align:center;border-top:1px solid #fce7f3;'>
            <p style='margin:0;font-size:12px;color:#9080a0;'>
                &copy; " . date('Y') . " Kyoshi</p>
        </div>
    </div>
    </body></html>";

    $mail->send();
} catch (Exception $e) {
    error_log("Reschedule email failed (booking $booking_id): " . $mail->ErrorInfo);
    // Not fatal – continue
}

// ── 12. Bulk reschedule: redirect to next booking if queued ──────────────────
if (!empty($next_ids_raw)) {
    // Clean up the list
    $ids = array_values(array_filter(array_map('trim', explode(',', $next_ids_raw))));

    if (!empty($ids)) {
        $nextId        = intval($ids[0]);
        $remainingIds  = array_slice($ids, 1);
        $nextParam     = !empty($remainingIds) ? '&next=' . implode(',', $remainingIds) : '';

        // Flush any output before redirect
        if (ob_get_level()) ob_end_clean();

        header("Location: reschedule_booking.php?id={$nextId}{$nextParam}");
        exit();
    }
}

// ── 13. All done – back to the booking that was just rescheduled ─────────────
$_SESSION['success'] = "Reschedule request submitted! The tutor will review it shortly.";
header("Location: booking_detail.php?id=" . $booking_id);
exit();