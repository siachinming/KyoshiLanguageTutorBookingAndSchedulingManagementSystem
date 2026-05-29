<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$booking_id = intval($data['booking_id'] ?? 0);
$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'student';

if (!$booking_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid booking ID']);
    exit();
}

// Get booking class time
$bookingStmt = $conn->prepare("SELECT booking_date, booking_time FROM bookings WHERE id = ?");
$bookingStmt->bind_param("i", $booking_id);
$bookingStmt->execute();
$booking = $bookingStmt->get_result()->fetch_assoc();

$class_end = strtotime($booking['booking_date'] . ' ' . $booking['booking_time']) + (2 * 60 * 60); // 2 hours after class

// Find the most recent active log
$stmt = $conn->prepare("
    SELECT id, join_time 
    FROM meeting_logs 
    WHERE booking_id = ? AND participant_id = ? AND participant_role = ? 
    AND leave_time IS NULL
    ORDER BY join_time DESC 
    LIMIT 1
");
$stmt->bind_param("iis", $booking_id, $user_id, $role);
$stmt->execute();
$log = $stmt->get_result()->fetch_assoc();

if (!$log) {
    echo json_encode(['success' => false, 'message' => 'No active meeting session found']);
    exit();
}

// Use current time or class end time (whichever is earlier)
$leave_time = min(time(), $class_end);
$duration = round((strtotime($leave_time) - strtotime($log['join_time'])) / 60);

$update = $conn->prepare("
    UPDATE meeting_logs 
    SET leave_time = FROM_UNIXTIME(?), duration_minutes = ? 
    WHERE id = ?
");
$update->bind_param("sii", date('Y-m-d H:i:s', $leave_time), $duration, $log['id']);

if ($update->execute()) {
    echo json_encode([
        'success' => true, 
        'message' => 'Session ended. Duration: ' . $duration . ' minutes'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to record leave time']);
}
?>