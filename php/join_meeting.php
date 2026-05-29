<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$userID = $_SESSION['user_id'];
$userRole = $_SESSION['role'];
$booking_id = intval($_GET['booking_id'] ?? 0);
$meeting_link = $_GET['link'] ?? '';

if (!$booking_id || !$meeting_link) {
    header("Location: booking_requests.php");
    exit();
}

// Verify user has access to this booking
if ($userRole === 'tutor') {
    $checkStmt = $conn->prepare("SELECT id, booking_date, booking_time, status FROM bookings WHERE id = ? AND tutor_id = ?");
    $checkStmt->bind_param("ii", $booking_id, $userID);
} else {
    $checkStmt = $conn->prepare("SELECT id, booking_date, booking_time, status FROM bookings WHERE id = ? AND student_id = ?");
    $checkStmt->bind_param("ii", $booking_id, $userID);
}
$checkStmt->execute();
$booking = $checkStmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: booking_requests.php");
    exit();
}

// ========== TIME VALIDATION ==========
$class_start = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
$current_time = time();
$minutes_until_class = round(($class_start - $current_time) / 60);

if ($current_time < strtotime('-15 minutes', $class_start)) {
    $days_early = floor(abs($minutes_until_class) / 1440);
    $hours_early = floor((abs($minutes_until_class) % 1440) / 60);
    $mins_early = abs($minutes_until_class) % 60;
    
    $time_message = "";
    if ($days_early > 0) $time_message .= $days_early . " days, ";
    if ($hours_early > 0) $time_message .= $hours_early . " hours, ";
    $time_message .= $mins_early . " minutes";
    
    $_SESSION['error_message'] = "Class is on " . date('l, F j, Y', $class_start) . " at " . date('g:i A', $class_start) . 
                                  ". You can only join 15 minutes before class time. (Currently " . $time_message . " early)";
    header("Location: booking_detail.php?id=" . $booking_id . "#online-session");
    exit();
}

// Also check if booking is confirmed (not pending or cancelled)
if ($booking['status'] !== 'confirmed' && $booking['status'] !== 'completed') {
    $_SESSION['error_message'] = "This session is not confirmed yet. Please wait for payment verification before joining.";
    header("Location: booking_detail.php?id=" . $booking_id . "#online-session");
    exit();
}

// Optional: Allow joining up to 30 minutes AFTER class start (late join)
if ($current_time > strtotime('+30 minutes', $class_start)) {
    $_SESSION['error_message'] = "This session started more than 30 minutes ago. If you missed it, please contact your tutor to reschedule.";
    header("Location: booking_detail.php?id=" . $booking_id);
    exit();
}
// ========== END TIME VALIDATION ==========

// ========== ADD THIS - Close any existing active records ==========
$closeStmt = $conn->prepare("
    UPDATE meeting_logs 
    SET leave_time = NOW(), 
        duration_minutes = TIMESTAMPDIFF(MINUTE, join_time, NOW())
    WHERE booking_id = ? AND participant_id = ? AND participant_role = ? 
    AND leave_time IS NULL
");
$closeStmt->bind_param("iis", $booking_id, $userID, $userRole);
$closeStmt->execute();
// ========== END CLOSE EXISTING RECORDS ==========

// Record that user joined the meeting
$stmt = $conn->prepare("
    INSERT INTO meeting_logs (booking_id, participant_id, participant_name, participant_role, join_time) 
    SELECT ?, ?, u.fullname, ?, NOW()
    FROM users u WHERE u.id = ?
");
$stmt->bind_param("iisi", $booking_id, $userID, $userRole, $userID);
$stmt->execute();

// Decode the meeting link and redirect
$meeting_link = urldecode($meeting_link);
header("Location: " . $meeting_link);
exit();
?>