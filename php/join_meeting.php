<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$booking_id = intval($_GET['booking_id'] ?? 0);
$meeting_link = $_GET['link'] ?? '';

// DECODE the URL first (fixes the double-encoding issue)
$meeting_link = urldecode($meeting_link);

if (!$booking_id || !$meeting_link) {
    die("Invalid meeting link");
}

// Validate URL format
if (!filter_var($meeting_link, FILTER_VALIDATE_URL)) {
    // If validation fails, try to prepend https://
    if (preg_match('/^meet\.google\.com/', $meeting_link)) {
        $meeting_link = 'https://' . $meeting_link;
    } elseif (!preg_match('/^https?:\/\//', $meeting_link)) {
        die("Invalid meeting URL format. URL must start with http:// or https://");
    }
}

$userID = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get user's name
$userStmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$userStmt->bind_param("i", $userID);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$participant_name = $user['fullname'] ?? ($role === 'tutor' ? 'Tutor' : 'Student');
$participant_role = ($role === 'tutor') ? 'tutor' : 'student';

// Check if there's already an active session (no leave_time) for this booking and participant
$checkStmt = $conn->prepare("
    SELECT id, join_time FROM meeting_logs 
    WHERE booking_id = ? AND participant_name = ? AND leave_time IS NULL
    ORDER BY join_time DESC LIMIT 1
");
$checkStmt->bind_param("is", $booking_id, $participant_name);
$checkStmt->execute();
$activeSession = $checkStmt->get_result()->fetch_assoc();

if ($activeSession) {
    // User already has an active session, just redirect
    error_log("User $participant_name already has active session for booking $booking_id");
} else {
    // Log the join time
    $stmt = $conn->prepare("
        INSERT INTO meeting_logs (booking_id, participant_name, participant_role, join_time) 
        VALUES (?, ?, ?, NOW())
    ");
    $stmt->bind_param("iss", $booking_id, $participant_name, $participant_role);
    
    if (!$stmt->execute()) {
        error_log("Failed to log meeting join: " . $conn->error);
    } else {
        error_log("Meeting join logged for booking $booking_id, participant: $participant_name");
    }
    $stmt->close();
}

// Redirect to the actual meeting link (use absolute redirect)
header("Location: " . $meeting_link);
exit();
?>