<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = $data['booking_id'] ?? 0;

if (!$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

$userID = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get user's name
$userStmt = $conn->prepare("SELECT fullname FROM users WHERE id = ?");
$userStmt->bind_param("i", $userID);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$participant_name = $user['fullname'] ?? ($role === 'tutor' ? 'Tutor' : 'Student');

// Find the active session for this user
$findStmt = $conn->prepare("
    SELECT id, join_time FROM meeting_logs 
    WHERE booking_id = ? AND participant_name = ? AND leave_time IS NULL
    ORDER BY join_time DESC LIMIT 1
");
$findStmt->bind_param("is", $booking_id, $participant_name);
$findStmt->execute();
$activeLog = $findStmt->get_result()->fetch_assoc();

if (!$activeLog) {
    echo json_encode(['success' => false, 'message' => 'No active session found for you']);
    exit();
}

// Calculate duration
$join_time = strtotime($activeLog['join_time']);
$leave_time = time();
$duration_minutes = round(($leave_time - $join_time) / 60);

// Update the log with leave time and duration
$updateStmt = $conn->prepare("
    UPDATE meeting_logs 
    SET leave_time = NOW(), duration_minutes = ? 
    WHERE id = ?
");
$updateStmt->bind_param("ii", $duration_minutes, $activeLog['id']);

if ($updateStmt->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Leave time recorded. Duration: ' . $duration_minutes . ' minutes'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $conn->error]);
}
?>