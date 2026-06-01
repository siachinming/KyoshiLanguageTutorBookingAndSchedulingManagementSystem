<?php
session_start();
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['hasConflict' => false]);
    exit();
}

$studentId = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);
$date = $data['date'];
$time = $data['time'];

// Check if student has ANY booking at this date and time (any tutor)
$stmt = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM bookings 
    WHERE student_id = ? 
    AND booking_date = ? 
    AND booking_time = ?
    AND status IN ('pending', 'accepted', 'confirmed')
");
$stmt->bind_param("iss", $studentId, $date, $time);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

echo json_encode(['hasConflict' => $result['count'] > 0]);
?>