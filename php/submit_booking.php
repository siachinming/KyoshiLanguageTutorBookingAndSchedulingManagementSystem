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

$userID   = $_SESSION['user_id'];
$tutorID  = intval($_POST['tutor_id'] ?? 0);
$language = trim($_POST['language'] ?? '');
$mode     = trim($_POST['mode'] ?? '');
$focus    = trim($_POST['focus'] ?? '');
$notes    = trim($_POST['notes'] ?? '');
$location = trim($_POST['location'] ?? '');
$dates    = $_POST['booking_date'] ?? [];
$times    = $_POST['booking_time'] ?? [];

// Basic validation — focus is optional, location only required for face_to_face
if (!$tutorID || !$language || !$mode || empty($dates)) {
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=missing_fields");
    exit();
}
if ($mode === 'face_to_face' && empty($location)) {
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=missing_location");
    exit();
}

// Verify tutor exists and is approved
$stmt = $conn->prepare("SELECT id FROM users WHERE id = ? AND role = 'tutor' AND status = 'approved'");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
if (!$stmt->get_result()->fetch_assoc()) {
    header("Location: search_tutors.php");
    exit();
}
$stmt->close();
$stmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Validate and deduplicate slots
$MAX_SLOTS_PER_DAY = 2;
$validSlots = [];
$slotsByDay = [];

foreach ($dates as $i => $date) {
    $time = $times[$i] ?? '';

    // Format check
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) continue;

    // Deduplicate same date+time
    $key = $date . '|' . $time;
    if (isset($validSlots[$key])) continue;

    // Max 2 slots per day
    $slotsByDay[$date] = ($slotsByDay[$date] ?? 0) + 1;
    if ($slotsByDay[$date] > $MAX_SLOTS_PER_DAY) continue;

    $validSlots[$key] = ['date' => $date, 'time' => $time];
}

if (empty($validSlots)) {
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=no_valid_slots");
    exit();
}

// Server-side overbooking check — slots already taken by another student
$checkSlots = array_values($validSlots);
$conflictFound = false;

$checkStmt = $conn->prepare("
    SELECT id FROM bookings
    WHERE tutor_id = ?
      AND booking_date = ?
      AND booking_time = ?
      AND status IN ('pending', 'accepted', 'confirmed')
    LIMIT 1
");

foreach ($checkSlots as $slot) {
    $checkStmt->bind_param("iss", $tutorID, $slot['date'], $slot['time']);
    $checkStmt->execute();
    if ($checkStmt->get_result()->fetch_assoc()) {
        $conflictFound = true;
        break;
    }
}
$checkStmt->close();

if ($conflictFound) {
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=slot_taken");
    exit();
}

// Insert all valid slots in a transaction
$lastID = null;
$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
        INSERT INTO bookings
            (student_id, tutor_id, language, learning_mode, booking_date, booking_time,
             status, meeting_location, notes, focus, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, NOW())
    ");

    $meetingLoc = $mode === 'face_to_face' ? $location : null;

    foreach ($checkSlots as $slot) {
        $stmt->bind_param("issssssss",
            $userID,
            $tutorID,
            $language,
            $mode,
            $slot['date'],
            $slot['time'],
            $meetingLoc,
            $notes,
            $focus
        );
        $stmt->execute();
        $lastID = $conn->insert_id;
    }

    $conn->commit();
    $stmt->close();
} catch (Exception $e) {
    $conn->rollback();
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=failed");
    exit();
}

$slotLines = '';
foreach ($checkSlots as $slot) {
    $dateFormatted = date('l, d M Y', strtotime($slot['date']));
    $timeFormatted = date('g:i A', strtotime($slot['time']));
    $slotLines .= "
        <tr>
            <td style='padding:10px 14px;border-bottom:1px solid #fce7f3;color:#342635;font-weight:600;'>
                {$dateFormatted}
            </td>
            <td style='padding:10px 14px;border-bottom:1px solid #fce7f3;color:#342635;'>
                {$timeFormatted}
            </td>
        </tr>
    ";
}

$modeLabel    = $mode === 'online' ? '💻 Online' : '🤝 Face to Face';
$locationLine = ($mode === 'face_to_face' && $location)
    ? "<p style='margin:6px 0;'><strong>📍 Location:</strong> {$location}</p>"
    : '';
$focusLine    = $focus
    ? "<p style='margin:6px 0;'><strong>🎯 Focus Areas:</strong> {$focus}</p>"
    : '';
$notesLine    = $notes
    ? "<p style='margin:6px 0;'><strong>📝 Student Notes:</strong> {$notes}</p>"
    : '';

$tutorDashboardLink = "http://localhost/kyoshi/php/tutor_dashboard.php";

$emailBody = "
<div style='font-family:Segoe UI,Arial,sans-serif;max-width:580px;margin:auto;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 8px 30px rgba(201,79,134,.12);'>

    <!-- Header -->
    <div style='background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:32px 32px 24px;text-align:center;'>
        <h1 style='margin:0;color:white;font-size:24px;letter-spacing:-0.5px;'>📚 New Booking Request</h1>
        <p style='margin:8px 0 0;color:rgba(255,255,255,.88);font-size:14px;'>You have a new session request on Kyoshi</p>
    </div>

    <!-- Body -->
    <div style='padding:28px 32px;'>
        <p style='margin:0 0 20px;font-size:15px;color:#342635;'>
            Hi <strong>{$tutor['fullname']}</strong>, 👋
        </p>
        <p style='margin:0 0 20px;font-size:14px;color:#7B6178;line-height:1.6;'>
            <strong style='color:#342635;'>{$student['fullname']}</strong> has requested a tutoring session with you. 
            Review the details below and accept or decline from your dashboard.
        </p>

        <!-- Session Details -->
        <div style='background:#FFF1F6;border:1px solid #fce7f3;border-radius:16px;padding:20px;margin-bottom:20px;'>
            <p style='margin:0 0 12px;font-size:13px;font-weight:700;color:#C94F86;text-transform:uppercase;letter-spacing:.5px;'>Session Details</p>
            <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>🌐 Language:</strong> {$language}</p>
            <p style='margin:6px 0;font-size:14px;color:#342635;'><strong>📖 Mode:</strong> {$modeLabel}</p>
            {$locationLine}
            {$focusLine}
            {$notesLine}
        </div>

        <!-- Slots Table -->
        <p style='margin:0 0 10px;font-size:13px;font-weight:700;color:#C94F86;text-transform:uppercase;letter-spacing:.5px;'>Requested Time Slot(s)</p>
        <table style='width:100%;border-collapse:collapse;border-radius:12px;overflow:hidden;border:1px solid #fce7f3;margin-bottom:24px;'>
            <thead>
                <tr style='background:linear-gradient(135deg,#E75A9B,#F28AB2);'>
                    <th style='padding:10px 14px;color:white;font-size:13px;text-align:left;'>Date</th>
                    <th style='padding:10px 14px;color:white;font-size:13px;text-align:left;'>Time</th>
                </tr>
            </thead>
            <tbody>
                {$slotLines}
            </tbody>
        </table>

        <!-- CTA -->
        <div style='text-align:center;'>
            <a href='{$tutorDashboardLink}'
               style='display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                      color:white;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px;
                      box-shadow:0 8px 20px rgba(231,90,155,.28);'>
                View &amp; Respond on Dashboard →
            </a>
        </div>

        <p style='margin:24px 0 0;font-size:12px;color:#9080a0;text-align:center;line-height:1.6;'>
            Please respond within <strong>48 hours</strong> so the student can confirm their schedule.<br>
            If you have questions, contact us at <a href='mailto:sohisabella87@gmail.com' style='color:#E75A9B;'>sohisabella87@gmail.com</a>
        </p>
    </div>

    <!-- Footer -->
    <div style='background:#FFF1F6;padding:16px 32px;text-align:center;border-top:1px solid #fce7f3;'>
        <p style='margin:0;font-size:12px;color:#9080a0;'>
            © " . date('Y') . " Kyoshi · You're receiving this because you're a registered tutor.
        </p>
    </div>
</div>
";

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
    $mail->addAddress($tutor['email'], $tutor['fullname']);
    $mail->addReplyTo($student['email'], $student['fullname']);

    $mail->isHTML(true);
    $mail->Subject = '📚 New Booking Request from ' . $student['fullname'] . ' · Kyoshi';
    $mail->Body    = $emailBody;
    $mail->AltBody = "Hi {$tutor['fullname']}, {$student['fullname']} has requested a session with you. Log in to your dashboard to respond: {$tutorDashboardLink}";

    $mail->send();

} catch (Exception $e) {
    // Email failed — booking is already saved, so don't block the redirect.
    // Optionally log: error_log("Booking email failed: " . $mail->ErrorInfo);
}

header("Location: booking_success.php?id=" . $lastID);
exit();
?>