<?php
session_start();
header('Content-Type: application/json');
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['hasConflict' => false, 'message' => 'Not logged in']);
    exit();
}

$userID = $_SESSION['user_id'];
$data = json_decode(file_get_contents('php://input'), true);

$date = $data['date'] ?? '';
$time = $data['time'] ?? '';
$exclude_booking_id = $data['exclude_booking_id'] ?? 0;

if (!$date || !$time) {
    echo json_encode(['hasConflict' => false, 'message' => 'Invalid request']);
    exit();
}

// Check if student has another booking at the same date/time with ANY tutor
$stmt = $conn->prepare("
    SELECT b.id, b.language, u.fullname as tutor_name
    FROM bookings b
    JOIN users u ON b.tutor_id = u.id
    WHERE b.student_id = ? 
    AND b.booking_date = ?
    AND b.booking_time = ?
    AND b.status NOT IN ('cancelled', 'rejected')
    AND b.id != ?
    LIMIT 1
");
$stmt->bind_param("issi", $userID, $date, $time, $exclude_booking_id);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    echo json_encode([
        'hasConflict' => true, 
        'message' => 'You already have a booking at this time',
        'tutor_name' => $row['tutor_name'],
        'booking_id' => $row['id']
    ]);
} else {
    echo json_encode(['hasConflict' => false, 'message' => 'Available']);
}
$stmt->close();
?>