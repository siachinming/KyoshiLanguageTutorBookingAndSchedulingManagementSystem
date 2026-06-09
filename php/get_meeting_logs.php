<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['error' => 'Unauthorized', 'logs' => []]);
    exit();
}

$booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$booking_id) {
    echo json_encode(['logs' => []]);
    exit();
}

// Get meeting logs for this booking - NO JOIN needed since participant_name is stored directly
$stmt = $conn->prepare("
    SELECT 
        id,
        booking_id,
        participant_name,
        participant_role,
        join_time,
        leave_time,
        duration_minutes
    FROM meeting_logs
    WHERE booking_id = ?
    ORDER BY join_time ASC
");
$stmt->bind_param("i", $booking_id);
$stmt->execute();
$result = $stmt->get_result();
$logs = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode(['logs' => $logs]);
?>