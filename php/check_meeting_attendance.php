<?php
session_start();
header('Content-Type: application/json');
include 'config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$booking_id = intval($_GET['booking_id'] ?? 0);
$tutor_id = intval($_GET['tutor_id'] ?? 0);

if ($booking_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

// Check if tutor joined the meeting
$stmt = $conn->prepare("
    SELECT id, join_time, leave_time, duration_minutes 
    FROM meeting_logs 
    WHERE booking_id = ? AND participant_role = 'tutor'
    ORDER BY join_time DESC
    LIMIT 1
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();

$tutor_attended = ($log && $log['join_time']) ? true : false;

echo json_encode([
    'success' => true,
    'tutor_attended' => $tutor_attended,
    'join_time' => $log ? date('d M Y, g:i A', strtotime($log['join_time'])) : null,
    'duration' => $log ? $log['duration_minutes'] : 0
]);
?>