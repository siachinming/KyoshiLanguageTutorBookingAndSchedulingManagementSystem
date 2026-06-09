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
    // Redirect based on role
    if ($userRole === 'tutor') {
        header("Location: booking_requests.php?tab=upcoming");
    } else {
        header("Location: my_bookings.php");
    }
    exit();
}

// Verify user has access to this booking
if ($userRole === 'tutor') {
    $checkStmt = $conn->prepare("SELECT id, booking_date, booking_time, status FROM bookings WHERE id = ? AND tutor_id = ?");
    $checkStmt->bind_param("ii", $booking_id, $userID);
    $redirect_page = "booking_requests.php?tab=upcoming";
    $detail_page = "tutor_booking_detail.php?id=" . $booking_id;
} else {
    $checkStmt = $conn->prepare("SELECT id, booking_date, booking_time, status FROM bookings WHERE id = ? AND student_id = ?");
    $checkStmt->bind_param("ii", $booking_id, $userID);
    $redirect_page = "my_bookings.php";
    $detail_page = "booking_detail.php?id=" . $booking_id . "#online-session";
}
$checkStmt->execute();
$booking = $checkStmt->get_result()->fetch_assoc();

if (!$booking) {
    header("Location: " . $redirect_page);
    exit();
}

// ========== TIME VALIDATION ==========
$class_start = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']);
$current_time = time();

// Tutors can join 30 min early, students 15 min early
$allow_before_minutes = ($userRole === 'tutor') ? 30 : 15;

if ($current_time < strtotime("-$allow_before_minutes minutes", $class_start)) {
    $minutes_until = round(($class_start - $current_time) / 60);
    $hours_early = floor($minutes_until / 60);
    $mins_early = $minutes_until % 60;
    
    $time_message = "";
    if ($hours_early > 0) $time_message .= $hours_early . " hours, ";
    $time_message .= $mins_early . " minutes";
    
    $_SESSION['error_message'] = "Class is on " . date('l, F j, Y', $class_start) . " at " . date('g:i A', $class_start) . 
                                  ". You can join " . $allow_before_minutes . " minutes before class time. (Currently " . $time_message . " early)";
    header("Location: " . $detail_page);
    exit();
}

// Check if booking is confirmed (not pending or cancelled)
if ($booking['status'] !== 'confirmed' && $booking['status'] !== 'completed') {
    $_SESSION['error_message'] = "This session is not confirmed yet. Please wait for payment verification before joining.";
    header("Location: " . $detail_page);
    exit();
}

// Allow joining up to 30 minutes AFTER class start for students, 60 min for tutors
$allow_after_minutes = ($userRole === 'tutor') ? 60 : 30;

if ($current_time > strtotime("+$allow_after_minutes minutes", $class_start)) {
    $_SESSION['error_message'] = "This session started more than " . $allow_after_minutes . " minutes ago. If you missed it, please contact your " . ($userRole === 'tutor' ? 'student' : 'tutor') . " to reschedule.";
    header("Location: " . $detail_page);
    exit();
}
// ========== END TIME VALIDATION ==========

// ========== CLOSE ANY EXISTING ACTIVE RECORDS ==========
$closeStmt = $conn->prepare("
    UPDATE meeting_logs 
    SET leave_time = NOW(), 
        duration_minutes = TIMESTAMPDIFF(MINUTE, join_time, NOW())
    WHERE booking_id = ? AND participant_id = ? AND participant_role = ? 
    AND leave_time IS NULL
");
$closeStmt->bind_param("iis", $booking_id, $userID, $userRole);
$closeStmt->execute();

// ========== RECORD THAT USER JOINED THE MEETING ==========
$stmt = $conn->prepare("
    INSERT INTO meeting_logs (booking_id, participant_id, participant_name, participant_role, join_time) 
    SELECT ?, ?, u.fullname, ?, NOW()
    FROM users u WHERE u.id = ?
");
$stmt->bind_param("iisi", $booking_id, $userID, $userRole, $userID);
$stmt->execute();

// Decode the meeting link and redirect
$meeting_link = urldecode($meeting_link);

// Make sure the link has http:// or https://
if (!preg_match('/^https?:\/\//', $meeting_link)) {
    $meeting_link = 'https://' . $meeting_link;
}

header("Location: " . $meeting_link);
exit();
?>