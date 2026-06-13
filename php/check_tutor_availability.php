<?php
session_start();
include 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['available' => false, 'error' => 'Unauthorized']);
    exit();
}

$tutor_id = isset($_GET['tutor_id']) ? intval($_GET['tutor_id']) : 0;
$booking_date = isset($_GET['date']) ? $_GET['date'] : '';
$booking_time = isset($_GET['time']) ? $_GET['time'] : '';
$current_booking_id = isset($_GET['booking_id']) ? intval($_GET['booking_id']) : 0;

if (!$tutor_id || !$booking_date || !$booking_time) {
    echo json_encode(['available' => true, 'warning' => 'Missing parameters']);
    exit();
}

// Check if tutor has existing booking at this time
$check_query = $conn->prepare("
    SELECT COUNT(*) as count 
    FROM bookings 
    WHERE tutor_id = ? 
    AND booking_date = ? 
    AND booking_time = ?
    AND status NOT IN ('cancelled', 'rejected')
    AND id != ?
");
$check_query->bind_param("issi", $tutor_id, $booking_date, $booking_time, $current_booking_id);
$check_query->execute();
$result = $check_query->get_result()->fetch_assoc();

$available = $result['count'] == 0;

echo json_encode([
    'available' => $available,
    'message' => $available ? 'Time is available' : 'Tutor already has a booking at this time'
]);
?>