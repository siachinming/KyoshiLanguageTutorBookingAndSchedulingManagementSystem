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
$proficiency_level = $_POST['proficiency_level'] ?? 'beginner';
$notes    = trim($_POST['notes'] ?? '');
$location = trim($_POST['location'] ?? '');
$dates    = $_POST['booking_date'] ?? [];
$times    = $_POST['booking_time'] ?? [];

// Basic validation
if (!$tutorID || !$language || !$mode || empty($dates)) {
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=missing_fields");
    exit();
}
if ($mode === 'face_to_face' && empty($location)) {
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=missing_location");
    exit();
}

// Verify tutor exists
$stmt = $conn->prepare("SELECT id, fullname, email FROM users WHERE id = ? AND role = 'tutor' AND status = 'approved'");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$tutor = $stmt->get_result()->fetch_assoc();
if (!$tutor) {
    header("Location: search_tutors.php");
    exit();
}
$stmt->close();

// Get student info
$stmt = $conn->prepare("SELECT fullname, email FROM users WHERE id = ?");
$stmt->bind_param("i", $userID);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Get tutor rate
$stmt = $conn->prepare("SELECT rate FROM tutor_profiles WHERE user_id = ?");
$stmt->bind_param("i", $tutorID);
$stmt->execute();
$tutorProfile = $stmt->get_result()->fetch_assoc();
$hourlyRate = floatval($tutorProfile['rate'] ?? 0);
$stmt->close();

// Validate slots
$MAX_SLOTS_PER_DAY = 2;
$validSlots = [];
$slotsByDay = [];

foreach ($dates as $i => $date) {
    $time = $times[$i] ?? '';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
    if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) continue;
    
    $key = $date . '|' . $time;
    if (isset($validSlots[$key])) continue;
    
    $slotsByDay[$date] = ($slotsByDay[$date] ?? 0) + 1;
    if ($slotsByDay[$date] > $MAX_SLOTS_PER_DAY) continue;
    
    $validSlots[$key] = ['date' => $date, 'time' => $time];
}

if (empty($validSlots)) {
    header("Location: booking_form.php?tutor_id=" . $tutorID . "&error=no_valid_slots");
    exit();
}

$checkSlots = array_values($validSlots);
$numberOfSessions = count($checkSlots);
$totalAmount = $hourlyRate * $numberOfSessions;

// Check for conflicts
$conflictFound = false;
$checkStmt = $conn->prepare("SELECT id FROM bookings WHERE tutor_id = ? AND booking_date = ? AND booking_time = ? AND status IN ('pending', 'accepted', 'confirmed') LIMIT 1");

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

// Insert bookings
$lastID = null;
$conn->begin_transaction();

try {
    $stmt = $conn->prepare("
    INSERT INTO bookings (student_id, tutor_id, language, learning_mode, booking_date, booking_time, 
                          focus, proficiency_level, notes, meeting_location, status, total_amount, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
");


    $meetingLoc = $mode === 'face_to_face' ? $location : null;

    foreach ($checkSlots as $slot) {
       $stmt->bind_param("iissssssssd",  // ← 11 characters (ii + sssssssss + d)
    $userID,           // i
    $tutorID,          // i
    $language,         // s
    $mode,             // s
    $slot['date'],     // s
    $slot['time'],     // s
    $focus,            // s
    $proficiency_level,// s
    $notes,            // s
    $meetingLoc,       // s (this is 9th s)
    $hourlyRate        // d
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

// Build slot lines for email
$slotLines = '';
foreach ($checkSlots as $slot) {
    $dateFormatted = date('l, d M Y', strtotime($slot['date']));
    $timeFormatted = date('g:i A', strtotime($slot['time']));
    $slotLines .= "<tr>
            <td style='padding:10px 14px;border-bottom:1px solid #fce7f3;color:#342635;font-weight:600;'>{$dateFormatted}</td>
            <td style='padding:10px 14px;border-bottom:1px solid #fce7f3;color:#342635;'>{$timeFormatted}</td>
        </tr>";
}

$modeLabel = $mode === 'online' ? 'Online' : 'Face to Face';
$locationLine = ($mode === 'face_to_face' && $location) ? "<p style='margin:6px 0;'><strong>Location:</strong> {$location}</p>" : '';
$focusLine = $focus ? "<p style='margin:6px 0;'><strong>Focus Areas:</strong> {$focus}</p>" : '';
$notesLine = $notes ? "<p style='margin:6px 0;'><strong>Student Notes:</strong> {$notes}</p>" : '';

$tutorDashboardLink = "http://localhost/kyoshi/php/tutor_dashboard.php";

// Email body - NO EMOJIS, proper HTML
$emailBody = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>New Booking Request</title>
</head>
<body>
<div style="font-family:Segoe UI,Arial,sans-serif;max-width:580px;margin:auto;background:#fff;border-radius:20px;overflow:hidden;box-shadow:0 8px 30px rgba(201,79,134,.12);">
    <div style="background:linear-gradient(135deg,#E75A9B,#F28AB2);padding:32px 32px 24px;text-align:center;">
        <h1 style="margin:0;color:white;font-size:24px;">New Booking Request</h1>
        <p style="margin:8px 0 0;color:rgba(255,255,255,.88);font-size:14px;">You have a new session request on Kyoshi</p>
    </div>
    <div style="padding:28px 32px;">
        <p style="margin:0 0 20px;font-size:15px;color:#342635;">Hi <strong>' . htmlspecialchars($tutor['fullname']) . '</strong>,</p>
        <p style="margin:0 0 20px;font-size:14px;color:#7B6178;line-height:1.6;">
            <strong style="color:#342635;">' . htmlspecialchars($student['fullname']) . '</strong> has requested a tutoring session with you. 
            Review the details below and accept or decline from your dashboard.
        </p>
        <div style="background:#FFF1F6;border:1px solid #fce7f3;border-radius:16px;padding:20px;margin-bottom:20px;">
            <p style="margin:0 0 12px;font-size:13px;font-weight:700;color:#C94F86;">Session Details</p>
            <p style="margin:6px 0;font-size:14px;color:#342635;"><strong>Language:</strong> ' . htmlspecialchars($language) . '</p>
            <p style="margin:6px 0;font-size:14px;color:#342635;"><strong>Mode:</strong> ' . $modeLabel . '</p>
            <p style="margin:6px 0;font-size:14px;color:#342635;"><strong>Total Amount:</strong> RM ' . number_format($totalAmount, 2) . ' (' . $numberOfSessions . ' session(s) x RM ' . $hourlyRate . '/hr)</p>
            ' . $locationLine . '
            ' . $focusLine . '
            ' . $notesLine . '
        </div>
        <p style="margin:6px 0;font-size:14px;color:#342635;"><strong>Proficiency Level:</strong> ' . ucfirst($proficiency_level) . '</p>
        <p style="margin:0 0 10px;font-size:13px;font-weight:700;color:#C94F86;">Requested Time Slot(s)</p>
        <table style="width:100%;border-collapse:collapse;border-radius:12px;overflow:hidden;border:1px solid #fce7f3;margin-bottom:24px;">
            <thead>
                <tr style="background:linear-gradient(135deg,#E75A9B,#F28AB2);">
                    <th style="padding:10px 14px;color:white;font-size:13px;text-align:left;">Date</th>
                    <th style="padding:10px 14px;color:white;font-size:13px;text-align:left;">Time</th>
                </tr>
            </thead>
            <tbody>' . $slotLines . '</tbody>
        </table>
        <div style="text-align:center;">
            <a href="' . $tutorDashboardLink . '"
               style="display:inline-block;padding:14px 32px;background:linear-gradient(135deg,#E75A9B,#F28AB2);
                      color:white;border-radius:999px;text-decoration:none;font-weight:700;font-size:14px;">
                View and Respond on Dashboard
            </a>
        </div>
        <p style="margin:24px 0 0;font-size:12px;color:#9080a0;text-align:center;line-height:1.6;">
            Please respond within <strong>48 hours</strong> so the student can confirm their schedule.<br>
            If you have questions, contact us at <a href="mailto:sohisabella87@gmail.com" style="color:#E75A9B;">sohisabella87@gmail.com</a>
        </p>
    </div>
    <div style="background:#FFF1F6;padding:16px 32px;text-align:center;border-top:1px solid #fce7f3;">
        <p style="margin:0;font-size:12px;color:#9080a0;">&copy; ' . date('Y') . ' Kyoshi · You are receiving this because you are a registered tutor.</p>
    </div>
</div>
</body>
</html>';

// Send email
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
    $mail->CharSet = 'UTF-8';
    $mail->Subject = 'New Booking Request from ' . $student['fullname'] . ' - Kyoshi';
    $mail->Body    = $emailBody;
    $mail->AltBody = "Hi {$tutor['fullname']}, {$student['fullname']} has requested a session with you. Log in to your dashboard to respond: {$tutorDashboardLink}";

    $mail->send();
} catch (Exception $e) {
    error_log("Booking email failed for tutor {$tutor['email']}: " . $mail->ErrorInfo);
}

header("Location: booking_success.php?id=" . $lastID);
exit();
?>